<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan groundwork (Richard, Oct 27 2026): every account is on a plan (Free for now, no payments). Features are
 * checked against the plan in one place (App\Support\Plans), so paid tiers can be switched on later without
 * rewriting pages. plan_source says how the plan was set: default, staff (comp/test), or a future billing provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan')->default('free')->index();
            $table->string('plan_source')->default('default');
            $table->timestamp('plan_started_at')->nullable();
            $table->timestamp('plan_ends_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['plan', 'plan_source', 'plan_started_at', 'plan_ends_at', 'last_login_at']));
    }
};
