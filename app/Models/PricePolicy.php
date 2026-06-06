<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PricePolicy extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['policy_name', 'start_date', 'end_date', 'multiplier'];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'multiplier' => 'decimal:2',
    ];

    /**
     * Lấy multiplier áp dụng cho một khoảng ngày.
     * Nếu nhiều policy trùng → lấy multiplier cao nhất.
     */
    public static function getMultiplierForPeriod(Carbon $checkIn, Carbon $checkOut): float
    {
        $multiplier = self::where('start_date', '<=', $checkOut)
            ->where('end_date', '>=', $checkIn)
            ->max('multiplier');

        return (float) ($multiplier ?? 1.0);
    }
}
