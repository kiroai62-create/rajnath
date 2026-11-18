<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            // How far down the page the visitor scrolled (percent), sent with the time on page.
            $table->unsignedTinyInteger('depth')->nullable()->after('value');
            // A signed-in person's activity (Admin › Customers › Activity).
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'id']);
            $table->dropColumn('depth');
        });
    }
};
