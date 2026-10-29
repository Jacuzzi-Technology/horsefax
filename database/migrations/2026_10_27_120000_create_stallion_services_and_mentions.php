<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From StallionCompare (Richard, Oct 27 2026: "breeding fee for stallions, where the horse is standing at ... article
 * mentions"). A stallion service is what a published stallion page says about standing the horse: fee, chute fee,
 * breeding terms and the farm, town and website. No phone numbers or email addresses. A mention is an article that
 * names the horse: title and link only (the article text belongs to its publisher).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stallion_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('standing_at')->nullable();      // farm or stallion station
            $table->string('city')->nullable();
            $table->string('region')->nullable();           // state or province
            $table->string('country')->nullable();
            $table->string('website')->nullable();
            $table->bigInteger('fee_cents')->nullable();
            $table->string('fee_text')->nullable();         // "Private treaty" and the like
            $table->string('currency', 3)->nullable();
            $table->bigInteger('chute_fee_cents')->nullable();
            $table->string('breeding', 500)->nullable();    // "Frozen transported semen available"
            $table->string('source_url')->nullable();
            $table->date('observed_on')->nullable();
            $table->timestamps();
            $table->unique(['horse_id', 'data_source_id']);
        });

        Schema::create('horse_mentions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('title', 500);
            $table->string('url', 500);
            $table->string('publisher')->nullable();
            $table->date('published_on')->nullable();
            $table->timestamps();
            $table->unique(['horse_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horse_mentions');
        Schema::dropIfExists('stallion_services');
    }
};
