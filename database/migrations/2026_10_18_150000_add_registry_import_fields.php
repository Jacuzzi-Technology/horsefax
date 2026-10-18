<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * National registry open data (IFCE–SIRE, Oct 18 2026): country of birth, and a fingerprint of the published row so a
 * later edition of the file adds only new horses instead of duplicating everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horses', function (Blueprint $table) {
            $table->string('birth_country')->nullable();
            $table->string('external_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('horses', fn (Blueprint $t) => $t->dropColumn(['birth_country', 'external_key']));
    }
};
