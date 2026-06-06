<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Booking extends Model
{
    public static function boot()
    {
        parent::boot();

        static::updated(function ($booking) {
            if ($booking->wasChanged('status') && $booking->status === 'completed') {
                try {
                    $booking->loadMissing('rooms.roomType');
                    $roomTypes = $booking->rooms->map(fn($r) => $r->roomType)->unique('id');

                    if ($roomTypes->isNotEmpty() && $booking->customer_email) {
                        \Illuminate\Support\Facades\Mail::send('emails.review_request', compact('booking', 'roomTypes'), function ($message) use ($booking) {
                            $message->to($booking->customer_email)
                                    ->subject('Cảm ơn quý khách và Đánh giá phòng tại Royal Hotel');
                        });
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("Gửi email đánh giá thất bại cho booking #{$booking->id}: " . $e->getMessage());
                }
            }
        });
    }

    public function getCreatedAtColumn()
    {
        static $column = null;
        if ($column === null) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn($this->getTable(), 'booking_date')) {
                    $column = 'booking_date';
                } else {
                    $column = 'created_at';
                }
            } catch (\Throwable $e) {
                $column = 'created_at';
            }
        }
        return $column;
    }

    public function getUpdatedAtColumn()
    {
        static $column = null;
        if ($column === null) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn($this->getTable(), 'updated_at')) {
                    $column = 'updated_at';
                } else {
                    $column = null;
                }
            } catch (\Throwable $e) {
                $column = null;
            }
        }
        return $column;
    }
    
    protected $fillable = [
        'user_id', 'customer_name', 'customer_email', 'customer_phone',
        'check_in', 'check_out', 'actual_check_in', 'actual_check_out',
        'adult_count', 'child_count', 'total_price',
        'payment_method', 'payment_status', 'status',
        'cancelled_at', 'cancellation_reason',
        'refund_status', 'refund_amount',
    ];

    protected $casts = [
        'check_in'        => 'date',
        'check_out'       => 'date',
        'actual_check_in' => 'datetime',
        'actual_check_out'=> 'datetime',
        'cancelled_at'    => 'datetime',
        'total_price'     => 'decimal:2',
        'refund_amount'   => 'decimal:2',
    ];

    // ── Relations ──────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'booking_rooms');
    }

    public function paymentLogs()
    {
        return $this->hasMany(PaymentLog::class);
    }

    // ── Status Helpers ─────────────────────────────────────
    public function isPaid(): bool      { return $this->payment_status === 'paid'; }
    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }

    // ── Refund Rule ────────────────────────────────────────
    /**
     * Tính số ngày tối thiểu cần hủy trước để được hoàn tiền.
     * - Check-in T2→T6: cần hủy trước 5 ngày
     * - Check-in T7, CN, hoặc ngày lễ: cần hủy trước 3 ngày
     */
    public function refundDeadlineDays(): int
    {
        $checkIn    = Carbon::parse($this->check_in);
        $isWeekend  = in_array($checkIn->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY]);
        $isHoliday  = Holiday::isHoliday($checkIn);

        return ($isWeekend || $isHoliday) ? 7 : 5;
    }

    /**
     * Kiểm tra booking này còn trong thời hạn hoàn tiền không.
     */
    public function isRefundEligible(): bool
    {
        if (!$this->isPaid()) return false;

        $deadline = Carbon::parse($this->check_in)
            ->subDays($this->refundDeadlineDays())
            ->startOfDay();

        return Carbon::now()->lessThanOrEqualTo($deadline);
    }

    /**
     * Số ngày còn lại để được hoàn tiền (âm = đã quá hạn).
     */
    public function daysUntilRefundDeadline(): int
    {
        $deadline = Carbon::parse($this->check_in)->subDays($this->refundDeadlineDays());
        return (int) Carbon::now()->diffInDays($deadline, false);
    }
    public function getDepositAmountAttribute(): float
    {
        return round((float) $this->total_price * 0.5, 2);
    }
}
