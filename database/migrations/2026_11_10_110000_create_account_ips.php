<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The IP address (and browser) behind each sign-up and sign-in (Richard, Nov 10 2026), so staff can see several
 * accounts working from one address, a common way around the per-account record cap. Kept 12 months
 * (hf:record-limit-report prunes older rows); the privacy policy says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_ips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('ip', 45);
            $t->string('event', 10); // signup | login
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at');
            $t->index(['user_id', 'created_at']);
            $t->index('ip');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('signup_ip', 45)->nullable();
            $t->string('last_login_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_ips');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['signup_ip', 'last_login_ip']));
    }
};
