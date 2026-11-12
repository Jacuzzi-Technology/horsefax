<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per account (or signed-out address) per UTC day that hit the daily record limit (Richard, Nov 10 2026:
 * accounts capped at 20 records a day; a monthly report says how many get stopped). See hf:record-limit-report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_limit_hits', function (Blueprint $t) {
            $t->id();
            $t->date('day');
            $t->string('who', 80); // u:<user id> or ip:<address>
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('ip', 45)->nullable();
            $t->string('plan', 30)->nullable();
            $t->unsignedInteger('cap');
            $t->unsignedInteger('blocked')->default(1); // refused requests that day
            $t->timestamp('first_at');
            $t->timestamp('last_at');
            $t->unique(['day', 'who']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_limit_hits');
    }
};
