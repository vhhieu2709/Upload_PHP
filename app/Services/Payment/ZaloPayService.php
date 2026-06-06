<?php

namespace App\Services\Payment;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;

class ZaloPayService
{
    private string $appId;
    private string $key1;
    private string $key2;
    private string $endpoint;

    public function __construct()
    {
        $this->appId    = config('payment.zalopay.app_id');
        $this->key1     = config('payment.zalopay.key1');
        $this->key2     = config('payment.zalopay.key2');
        $this->endpoint = config('payment.zalopay.endpoint', 'https://sb-openapi.zalopay.vn/v2/create');
    }

    public function createPaymentUrl(Booking $booking): string
    {
        $appTransId = date('ymd') . '_' . $booking->id;
        $appTime    = round(microtime(true) * 1000);
        $amount = (int) $booking->deposit_amount;
        $embedData  = json_encode(['booking_id' => $booking->id]);
        $items      = json_encode([]);
        $description = "Khách sạn - Thanh toán đặt phòng #{$booking->id}";

        $data = $this->appId . '|' . $appTransId . '|' . $booking->user_id
             . '|' . $amount . '|' . $appTime . '|' . $embedData . '|' . $items;

        $mac = hash_hmac('sha256', $data, $this->key1);

        $response = Http::post($this->endpoint, [
            'app_id'       => $this->appId,
            'app_trans_id' => $appTransId,
            'app_user'     => 'hotel_' . $booking->user_id,
            'app_time'     => $appTime,
            'item'         => $items,
            'embed_data'   => $embedData,
            'amount'       => $amount,
            'description'  => $description,
            'bank_code'    => '',
            'callback_url' => route('webhook.zalopay'),
            'mac'          => $mac,
        ]);

        return $response->json('order_url') ?? route('payment.error', $booking->id);
    }

    public function verifyCallback(array $data): bool
    {
        $mac = hash_hmac('sha256', $data['data'] ?? '', $this->key2);
        return $mac === ($data['mac'] ?? '');
    }
}
