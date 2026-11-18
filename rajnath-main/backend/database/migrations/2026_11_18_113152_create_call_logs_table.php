<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What happened in each in-app call (website and app): joins, drops and why, reconnects, camera or mic problems.
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 12);
            $table->string('channel', 4);
            $table->string('event', 20);
            $table->string('detail', 200)->nullable();
            $table->string('device', 120)->nullable();
            $table->dateTime('created_at');
            $table->index(['booking_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
