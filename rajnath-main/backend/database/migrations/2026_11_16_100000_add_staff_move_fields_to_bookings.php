<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Set when the team moves a booking (for example to today): the earlier start and a note for the customer.
            $table->dateTime('moved_from_utc')->nullable();
            $table->string('moved_note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['moved_from_utc', 'moved_note']));
    }
};
