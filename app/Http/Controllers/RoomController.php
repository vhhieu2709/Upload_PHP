<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\PricePolicy;
use Illuminate\Http\Request;
use Carbon\Carbon;

class RoomController extends Controller
{
    /**
     * Danh sách loại phòng + form tìm kiếm
     */
    public function index()
    {
        $roomTypes = RoomType::with(['amenities', 'rooms'])
            ->withCount(['rooms as available_count' => function ($q) {
                $q->where('status', 'available');
            }])
            ->get();

        return view('room.index', compact('roomTypes'));
    }

    /**
     * Tìm phòng theo ngày, số khách
     */
    public function search(Request $request)
    {
        $request->validate([
            'check_in'  => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'adults'    => 'required|integer|min:1',
            'children'  => 'nullable|integer|min:0',
        ]);

        $checkIn   = $request->check_in;
        $checkOut  = $request->check_out;
        $adults    = (int) $request->adults;
        $children  = (int) ($request->children ?? 0);
        $guests    = $adults + $children;
        $nights    = Carbon::parse($checkIn)->diffInDays($checkOut);

        // Room_id đã bị đặt trùng ngày
        $bookedRoomIds = \DB::table('booking_rooms')
            ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
            ->where('bookings.status', '!=', 'cancelled')
            ->where('bookings.check_in', '<', $checkOut)
            ->where('bookings.check_out', '>', $checkIn)
            ->pluck('booking_rooms.room_id');

        // Lấy loại phòng có phòng trống và đủ sức chứa
        $roomTypes = RoomType::with('amenities')
            ->where('max_adults', '>=', $adults)
            ->where('max_guests', '>=', $guests)
            ->whereHas('rooms', function ($q) use ($bookedRoomIds) {
                $q->where('status', 'available')
                  ->whereNotIn('id', $bookedRoomIds);
            })
            ->withCount(['rooms as available_count' => function ($q) use ($bookedRoomIds) {
                $q->where('status', 'available')->whereNotIn('id', $bookedRoomIds);
            }])
            ->get();

        // Áp dụng price policy
        $multiplier = PricePolicy::getMultiplierForPeriod(
            Carbon::parse($checkIn),
            Carbon::parse($checkOut)
        );

        return view('room.search', compact(
            'roomTypes', 'checkIn', 'checkOut',
            'adults', 'children', 'nights', 'multiplier'
        ));
    }

    /**
     * Chi tiết loại phòng
     */
    public function detail(int $id)
    {
        $roomType = RoomType::with(['amenities', 'reviews.user'])->findOrFail($id);
        $avgRating = $roomType->averageRating();
        return view('room.detail', compact('roomType', 'avgRating'));
    }

    /**
     * Danh sách tiện nghi của loại phòng (AJAX)
     */
    public function amenities(int $id)
    {
        $roomType = RoomType::with('amenities')->findOrFail($id);
        return response()->json($roomType->amenities);
    }
}