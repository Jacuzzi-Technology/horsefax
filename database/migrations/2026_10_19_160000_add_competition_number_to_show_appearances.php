<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Show offices print the association's competition number beside the horse (A&M Show Services: the NRHA horse
 * number). It identifies the horse within that association, not a breed registration, so it's kept apart from
 * registration_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_appearances', function (Blueprint $table) {
            $table->string('competition_number')->nullable();
            $table->string('competition_registry')->nullable(); // NRHA
            $table->index(['competition_registry', 'competition_number']);
        });
    }

    public function down(): void
    {
        Schema::table('show_appearances', fn (Blueprint $t) => $t->dropColumn(['competition_number', 'competition_registry']));
    }
};
