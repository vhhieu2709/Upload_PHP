<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_number', 20)->unique();
            $table->foreignId('room_type_id')->constrained('room_types')->onUpdate('cascade');
            $table->integer('floor');
            $table->enum('status', [
                'available',    // Trống
                'booked',       // Đã đặt
                'occupied',     // Đang ở
                'cleaning',     // Đang dọn
                'maintenance',  // Bảo trì
                'overdue',      // Quá hạn
            ])->default('available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
