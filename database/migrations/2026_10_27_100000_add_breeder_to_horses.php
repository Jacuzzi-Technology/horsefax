<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Breeder, as an identity field on every record (Richard, Oct 27 2026: "good information that is publicly available").
 * Shown like color or breed, with its source and history in horse_facts. The breeder as a registry or stud book prints
 * it; owners stay unpublished (registry_owners is still recorded, not shown).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horses', function (Blueprint $table) {
            $table->string('breeder', 160)->nullable()->after('markings');
        });
    }

    public function down(): void
    {
        Schema::table('horses', function (Blueprint $table) {
            $table->dropColumn('breeder');
        });
    }
};
