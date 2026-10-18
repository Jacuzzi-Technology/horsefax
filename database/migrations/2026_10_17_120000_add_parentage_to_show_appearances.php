<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** NRCHA results list each horse's sire, dam and dam's sire, plus judges' scores. Kept as published, per result. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_appearances', function (Blueprint $table) {
            $table->string('sire_name')->nullable();
            $table->string('dam_name')->nullable();
            $table->string('dam_sire_name')->nullable();
            $table->string('score')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('show_appearances', fn (Blueprint $t) => $t->dropColumn(['sire_name', 'dam_name', 'dam_sire_name', 'score']));
    }
};
