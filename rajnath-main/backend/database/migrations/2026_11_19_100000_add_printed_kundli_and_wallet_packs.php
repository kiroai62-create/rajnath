<?php

use Database\Seeders\CatalogueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - report_jobs.delivery: where a printed report is posted (encrypted).
 * - The "Printed Lifetime Kundli" service (₹5,100) on sites seeded before it existed.
 * - Wallet top-up packs (₹500, ₹1,000, ₹2,100, ₹5,100) when none exist; the owner edits them in Finance › Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_jobs', function (Blueprint $table) {
            $table->text('delivery')->nullable()->after('question');
        });
        $report = DB::table('service_families')->where('code', 'report')->value('id');
        if ($report) {
            (new CatalogueSeeder)->printedKundli((int) $report);
        }
        if (! DB::table('recharge_packages')->exists()) {
            foreach ([50000, 100000, 210000, 510000] as $i => $minor) {
                DB::table('recharge_packages')->insert(['amount_minor' => $minor, 'bonus_minor' => 0, 'active' => true, 'sort' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('report_jobs', fn (Blueprint $t) => $t->dropColumn('delivery'));
    }
};
