<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Where a customer created their account (website or app), for Admin › Customers. Existing accounts: app when they ever signed in to the app. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_channel', 4)->nullable()->after('kind')->index();
        });
        DB::table('users')->where('kind', 'customer')->whereNull('signup_channel')->update(['signup_channel' => 'web']);
        DB::table('users')->where('kind', 'customer')->whereExists(fn ($q) => $q->from('personal_access_tokens')
            ->whereColumn('personal_access_tokens.tokenable_id', 'users.id')->where('personal_access_tokens.tokenable_type', 'App\\Models\\User'))
            ->whereNotExists(fn ($q) => $q->from('sessions')->whereColumn('sessions.user_id', 'users.id'))->update(['signup_channel' => 'app']);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('signup_channel'));
    }
};
