<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\PaymentLog;
use App\Models\Room;
use App\Services\Payment\VietQRService;
use App\Services\Payment\MoMoService;
use App\Services\Payment\ZaloPayService;
use App\Services\Payment\VNPayService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        protected VietQRService  $vietqr,
        protected MoMoService    $momo,
        protected ZaloPayService $zalopay,
        protected VNPayService   $vnpay,
    ) {}

    /**
     * Hiển thị trang thanh toán theo phương thức đã chọn.
     */
    public function show(int $bookingId)
    {
        $booking = Booking::with('rooms.roomType')->findOrFail($bookingId);

        if ($booking->isPaid()) {
            return redirect()->route('payment.success', $bookingId);
        }

        return match ($booking->payment_method) {
            'vietqr'  => $this->showVietQR($booking),
            'momo'    => $this->redirectMomo($booking),
            'zalopay' => $this->redirectZalopay($booking),
            'vnpay'   => $this->redirectVnpay($booking),
            default   => abort(400, 'Phương thức thanh toán không hợp lệ'),
        };
    }

    // ── VietQR ────────────────────────────────────────────
    private function showVietQR(Booking $booking)
    {
        $qrData = $this->vietqr->generateQR($booking);
        return view('payment.vietqr', compact('booking', 'qrData'));
    }

    /**
     * AJAX polling — frontend gọi mỗi 5 giây để check trạng thái.
     * Khi paid → trả về redirect URL → frontend tự chuyển trang.
     */
    public function checkStatus(int $bookingId): JsonResponse
    {
        $booking = Booking::findOrFail($bookingId);

        if ($booking->isPaid()) {
            return response()->json([
                'status'       => 'paid',
                'redirect_url' => route('payment.success', $bookingId),
            ]);
        }

        // Polling SePay/MB Bank API để check giao dịch mới
        $transaction = $this->vietqr->checkTransaction($booking);

        if ($transaction) {
            $this->confirmPayment($booking, 'vietqr', $transaction['id'], $transaction);
            return response()->json([
                'status'       => 'paid',
                'redirect_url' => route('payment.success', $bookingId),
            ]);
        }

        return response()->json(['status' => 'pending']);
    }

    // ── MoMo Webhook ──────────────────────────────────────
    public function webhookMomo(Request $request): JsonResponse
    {
        $data = $request->all();

        if (!$this->momo->verifySignature($data)) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $bookingId = $data['orderId'] ?? null;
        $booking   = Booking::find($bookingId);

        if (!$booking || $booking->isPaid()) {
            return response()->json(['message' => 'OK']);
        }

        if (($data['resultCode'] ?? -1) === 0) { // 0 = success
            $this->confirmPayment($booking, 'momo', $data['transId'], $data);
        } else {
            PaymentLog::create([
                'booking_id'   => $booking->id,
                'gateway'      => 'momo',
                'transaction_id'=> $data['transId'] ?? null,
                'amount'       => $booking->total_price,
                'status'       => 'failed',
                'raw_response' => $data,
            ]);
        }

        return response()->json(['message' => 'OK']);
    }

    // ── ZaloPay Webhook ───────────────────────────────────
    public function webhookZalopay(Request $request): JsonResponse
    {
        $data = $request->all();

        if (!$this->zalopay->verifyCallback($data)) {
            return response()->json(['return_code' => -1, 'return_message' => 'Invalid MAC']);
        }

        $embedData = json_decode($data['data'] ?? '{}', true);
        $bookingId = $embedData['booking_id'] ?? null;
        $booking   = Booking::find($bookingId);

        if (!$booking || $booking->isPaid()) {
            return response()->json(['return_code' => 1, 'return_message' => 'OK']);
        }

        if (($data['type'] ?? 0) === 1) { // 1 = payment success
            $parsed = json_decode($data['data'], true);
            $this->confirmPayment($booking, 'zalopay', $parsed['zp_trans_id'], $parsed);
        }

        return response()->json(['return_code' => 1, 'return_message' => 'OK']);
    }

    // ── VNPay Webhook (IPN) ───────────────────────────────
    public function webhookVnpay(Request $request): JsonResponse
    {
        $data = $request->all();

        if (!$this->vnpay->verifySecureHash($data)) {
            return response()->json(['RspCode' => '97', 'Message' => 'Invalid signature']);
        }

        $bookingId = $data['vnp_TxnRef'] ?? null;
        $booking   = Booking::find($bookingId);

        if (!$booking) {
            return response()->json(['RspCode' => '01', 'Message' => 'Order not found']);
        }

        if ($booking->isPaid()) {
            return response()->json(['RspCode' => '02', 'Message' => 'Already updated']);
        }

        if (($data['vnp_ResponseCode'] ?? '') === '00') {
            $this->confirmPayment($booking, 'vnpay', $data['vnp_TransactionNo'], $data);
        }

        return response()->json(['RspCode' => '00', 'Message' => 'Confirm success']);
    }

    /**
     * VNPay redirect người dùng về sau khi thanh toán.
     */
    public function vnpayReturn(Request $request)
    {
        $data = $request->all();

        if (!$this->vnpay->verifySecureHash($data)) {
            return redirect()->route('payment.error', $data['vnp_TxnRef'] ?? 0);
        }

        $bookingId = $data['vnp_TxnRef'] ?? null;

        if (($data['vnp_ResponseCode'] ?? '') === '00') {
            return redirect()->route('payment.success', $bookingId);
        }

        return redirect()->route('payment.error', $bookingId);
    }

    // ── Success / Error pages ─────────────────────────────
    public function success(int $bookingId)
    {
        $booking = Booking::with('rooms.roomType')->findOrFail($bookingId);
        return view('payment.success', compact('booking'));
    }

    public function error(int $bookingId)
    {
        $booking = Booking::findOrFail($bookingId);
        return view('payment.error', compact('booking'));
    }

    // ── Redirect helpers ──────────────────────────────────
    private function redirectMomo(Booking $booking)
    {
        $payUrl = $this->momo->createPaymentUrl($booking);
        return redirect()->away($payUrl);
    }

    private function redirectZalopay(Booking $booking)
    {
        $payUrl = $this->zalopay->createPaymentUrl($booking);
        return redirect()->away($payUrl);
    }

    private function redirectVnpay(Booking $booking)
    {
        $payUrl = $this->vnpay->createPaymentUrl($booking);
        return redirect()->away($payUrl);
    }

    // ── Core: xác nhận thanh toán thành công ─────────────
    private function confirmPayment(Booking $booking, string $gateway, string $transactionId, array $rawData): void
{
    \DB::transaction(function () use ($booking, $gateway, $transactionId, $rawData) {
        $booking = Booking::lockForUpdate()->find($booking->id);
        if ($booking->isPaid()) return;

        PaymentLog::create([
            'booking_id'     => $booking->id,
            'gateway'        => $gateway,
            'transaction_id' => $transactionId,
            'amount'         => $booking->total_price,
            'status'         => 'success',
            'raw_response'   => $rawData,
        ]);

        $booking->update([
            'payment_status' => 'paid',
            'status'         => 'confirmed',
        ]);

        foreach ($booking->rooms as $room) {
            $room->update(['status' => Room::STATUS_BOOKED]);
        }
    });
}
}
