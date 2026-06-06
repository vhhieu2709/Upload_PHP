<?php

namespace App\Services\Payment;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * VietQRService
 *
 * Luồng hoạt động:
 * 1. generateQR()  → tạo mã QR + reference_code duy nhất cho booking
 * 2. Frontend hiển thị QR, JS polling /payment/check/{id} mỗi 5 giây
 * 3. checkTransaction() → gọi SePay API kiểm tra giao dịch mới
 * 4. Nếu tìm thấy giao dịch khớp reference_code → trả về info
 * 5. PaymentController.confirmPayment() → cập nhật DB → trả redirect URL
 * 6. Frontend nhận redirect URL → tự chuyển trang (không cần nhấn nút)
 */
class VietQRService
{
    private string $bankBin;
    private string $accountNo;
    private string $accountName;
    private ?string $sePayToken;
    private string $sePayApiUrl;

    public function __construct()
    {
        $this->bankBin     = config('payment.vietqr.bank_bin');      // VD: '970422' (MB Bank)
        $this->accountNo   = config('payment.vietqr.account_no');
        $this->accountName = config('payment.vietqr.account_name');
        $this->sePayToken  = config('payment.vietqr.sepay_token');
        $this->sePayApiUrl = config('payment.vietqr.sepay_api_url', 'https://my.sepay.vn/userapi');
    }

    /**
     * Tạo QR data cho booking.
     * reference_code = "KS" + bookingId + random 4 ký tự (để khớp khi polling)
     */
    public function generateQR(Booking $booking): array
    {
        // Tạo nội dung chuyển khoản unique
        $existing = \App\Models\PaymentLog::where('booking_id', $booking->id)
            ->where('gateway', 'vietqr')->where('status', 'pending')->first();

        if ($existing) {
            $referenceCode = $existing->reference_code;
        } else {
            $referenceCode = 'KS' . $booking->id . strtoupper(Str::random(4));
            \App\Models\PaymentLog::create([
                'booking_id'     => $booking->id,
                'gateway'        => 'vietqr',
                'reference_code' => $referenceCode,
                'amount'         => $booking->deposit_amount,
                'status'         => 'pending',
            ]);
        }

        // URL ảnh QR từ VietQR CDN (không cần API key)
        $amount  = (int) $booking->deposit_amount;
        $qrUrl   = "https://img.vietqr.io/image/{$this->bankBin}-{$this->accountNo}-compact2.png"
            . "?amount={$amount}"
            . "&addInfo=" . urlencode($referenceCode)
            . "&accountName=" . urlencode($this->accountName);

        return [
            'qr_url'         => $qrUrl,
            'reference_code' => $referenceCode,
            'account_no'     => $this->accountNo,
            'account_name'   => $this->accountName,
            'bank_bin'       => $this->bankBin,
            'amount'         => $amount,
        ];
    }

    /**
     * Polling: gọi SePay API để tìm giao dịch mới khớp với booking.
     * Trả về transaction info nếu tìm thấy, null nếu chưa có.
     */
    public function checkTransaction(Booking $booking): ?array
    {
        // Lấy reference_code từ payment_log pending gần nhất
        $log = \App\Models\PaymentLog::where('booking_id', $booking->id)
            ->where('gateway', 'vietqr')
            ->where('status', 'pending')
            ->latest()
            ->first();

        if (!$log || !$log->reference_code) return null;

        // Gọi SePay API
        try {
            $response = Http::withToken($this->sePayToken)
                ->timeout(5)
                ->get("{$this->sePayApiUrl}/transactions/list", [
                    'transaction_content' => $log->reference_code,
                    'limit'               => 10,
                ]);

            if (!$response->successful()) return null;

            $transactions = $response->json('transactions', []);

            if (empty($transactions)) return null;

            // Tìm giao dịch có chứa reference_code trong nội dung
            $tx = collect($transactions)->first(function ($t) use ($log) {
                return str_contains($t['transaction_content'] ?? '', $log->reference_code);
            });

            if (!$tx) return null;

            // Kiểm tra số tiền khớp (±1000đ để tránh lỗi làm tròn)
            $received = (float) ($tx['amount_in'] ?? 0);
            if (abs($received - $booking->deposit_amount) > 1000) return null;

            // Cập nhật log thành success
            $log->update([
                'status'         => 'success',
                'transaction_id' => $tx['id'],
                'raw_response'   => $tx,
            ]);

            return $tx;

        } catch (\Exception $e) {
            \Log::warning("VietQR polling error for booking #{$booking->id}: " . $e->getMessage());
            return null;
        }
    }
}
