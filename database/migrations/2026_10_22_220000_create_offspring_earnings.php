<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sire and dam offspring earnings, computed by Horsefax from the show results it has harvested (Richard, Oct 22 2026:
 * build it ourselves, the way EquiStat does, since NRHA publishes no data). Staff only until the totals roughly match
 * NRHA's published milestones; Richard decides when they go public. hf:offspring-earnings rebuilds both tables nightly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offspring_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('horse_id')->constrained('horses')->cascadeOnDelete(); // the sire or dam
            $table->string('role', 4);                    // sire, dam
            $table->string('organization');               // 'all', or as published with the results (NRHA, NRCHA, ...)
            $table->integer('offspring')->default(0);     // foals on record with this parent
            $table->integer('starters')->default(0);      // of those, with any result (in this organization)
            $table->integer('earners')->default(0);       // of those, with any money (in this organization)
            $table->bigInteger('earnings_cents')->default(0);
            $table->smallInteger('first_year')->nullable();
            $table->smallInteger('last_year')->nullable();
            $table->timestamp('computed_at');
            $table->unique(['horse_id', 'role', 'organization']);
            $table->index(['role', 'organization', 'earnings_cents']);
        });
        Schema::create('offspring_earnings_runs', function (Blueprint $table) {
            $table->id();
            $table->json('stats');                        // coverage: what share of the money we can credit to a parent, and why not
            $table->integer('seconds');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offspring_earnings_runs');
        Schema::dropIfExists('offspring_earnings');
    }
};
