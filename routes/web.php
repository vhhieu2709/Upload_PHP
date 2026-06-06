<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CancellationController;
use App\Http\Controllers\AuthController;

// ============================================================
// PUBLIC — Không cần đăng nhập
// ============================================================
Route::get('/',           [HomeController::class, 'index'])->name('home');
Route::get('/about',      [HomeController::class, 'about'])->name('about');
Route::get('/contact',    [HomeController::class, 'contact'])->name('contact');

// Phòng
Route::get('/rooms',              [RoomController::class, 'index'])->name('rooms.index');
Route::get('/rooms/search',       [RoomController::class, 'search'])->name('rooms.search');
Route::get('/rooms/{id}',         [RoomController::class, 'detail'])->name('rooms.detail');
Route::get('/rooms/{id}/amenities',[RoomController::class, 'amenities'])->name('rooms.amenities');
Route::get('/amenities',          [RoomController::class, 'amenities'])->name('amenities');

// Auth
Route::middleware('guest')->group(function () {
    Route::get('/login',              [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',             [AuthController::class, 'login']);
    Route::get('/register',           [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register',          [AuthController::class, 'register']);
    Route::get('/verify',             [AuthController::class, 'showVerify'])->name('verify');
    Route::post('/verify',            [AuthController::class, 'verify']);
    Route::get('/forgot-password',    [AuthController::class, 'showForgot'])->name('password.forgot');
    Route::post('/forgot-password',   [AuthController::class, 'sendReset']);
    Route::get('/reset-password',     [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password',    [AuthController::class, 'reset']);
    Route::get('/verify-reset-otp',   [AuthController::class, 'showVerifyOtp'])->name('password.verify-otp');
    Route::post('/verify-reset-otp',  [AuthController::class, 'verifyOtp']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// ============================================================
// CUSTOMER — Cần đăng nhập + đã verify
// ============================================================
Route::middleware(['auth.custom', 'verified.custom'])->group(function () {

    // Booking
    Route::get('/booking/create',       [BookingController::class, 'create'])->name('booking.create');
    Route::post('/booking',             [BookingController::class, 'store'])->name('booking.store');
    Route::get('/booking/success/{id}', [BookingController::class, 'success'])->name('booking.success');
    Route::get('/my-bookings',          [BookingController::class, 'myBookings'])->name('booking.mine');

    // Thanh toán
    Route::get('/payment/{bookingId}/form',    [PaymentController::class, 'form'])->name('payment.form');
    Route::get('/payment/{bookingId}',         [PaymentController::class, 'show'])->name('payment.show');
    Route::get('/payment/success/{bookingId}', [PaymentController::class, 'success'])->name('payment.success');
    Route::get('/payment/error/{bookingId}',   [PaymentController::class, 'error'])->name('payment.error');
    Route::patch('/payment/{bookingId}',       [PaymentController::class, 'updateMethod'])->name('payment.update');

    // Polling endpoint cho VietQR (AJAX check mỗi 5 giây)
    Route::get('/payment/check/{bookingId}', [PaymentController::class, 'checkStatus'])->name('payment.check');

    // Hủy phòng
    Route::get('/booking/{id}/cancel',    [CancellationController::class, 'show'])->name('booking.cancel.show');
    Route::post('/booking/{id}/cancel',   [CancellationController::class, 'cancel'])->name('booking.cancel');
});

// ============================================================
// RECEPTIONIST + ADMIN
// ============================================================
Route::middleware(['auth.custom', 'role:receptionist,admin'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('/bookings',                  [BookingController::class, 'staffIndex'])->name('bookings');
    Route::patch('/bookings/{id}/confirm',   [BookingController::class, 'confirm'])->name('bookings.confirm');
    Route::patch('/bookings/{id}/checkin',   [BookingController::class, 'checkIn'])->name('bookings.checkin');
    Route::patch('/bookings/{id}/checkout',  [BookingController::class, 'checkOut'])->name('bookings.checkout');
    Route::patch('/bookings/{id}/refund',    [CancellationController::class, 'processRefund'])->name('bookings.refund');
});

Route::prefix('webhook')->name('webhook.')->group(function () {
    Route::post('/momo',        [PaymentController::class, 'webhookMomo'])->name('momo');
    Route::post('/zalopay',     [PaymentController::class, 'webhookZalopay'])->name('zalopay');
    Route::post('/vnpay',       [PaymentController::class, 'webhookVnpay'])->name('vnpay');
    Route::get('/vnpay/return', [PaymentController::class, 'vnpayReturn'])->name('vnpay.return');
});