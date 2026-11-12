<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richard, Oct 22 2026: keep the rider and owner printed with a result (NRHA lists both). They're for matching only:
 * the same owner or rider across years points to the same horse when results carry no registration number. Never
 * shown on public pages: people's names stay off horse records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_appearances', function (Blueprint $table) {
            $table->string('rider_name')->nullable();
            $table->string('owner_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('show_appearances', fn (Blueprint $t) => $t->dropColumn(['rider_name', 'owner_name']));
    }
};
