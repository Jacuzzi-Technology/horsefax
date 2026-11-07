<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry details read from AQHA papers and records reports (Richard, Oct 21 2026): owner history (current and
 * previous registered owners with dates acquired), parentage verified, how produced, health panel, deceased date,
 * markings, show/race summaries and each report's text. RECORDED, NOT SHOWN: nothing here is rendered on the site,
 * in reports or share links, or to owners. It keeps the option to show it later (blurred, or as a link to the AQHA
 * history once there's an affiliation). Street addresses and ZIP codes are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('registry');
            $table->string('registration_number');
            $table->string('document_kind');           // AQHA Records report, AQHA certificate, certificate back, ...
            $table->string('document_ref');            // order:<no> or file:<hash>
            $table->date('document_date')->nullable();
            $table->string('source')->nullable();      // the file name it was read from
            $table->jsonb('data')->nullable();
            $table->text('raw_text')->nullable();
            $table->timestamps();
            $table->unique(['registry', 'registration_number', 'document_ref']);
            $table->index('horse_id');
        });

        Schema::create('registry_owners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registry_record_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('role');                    // current | previous
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->date('date_acquired')->nullable();
            $table->timestamps();
            $table->index(['horse_id', 'date_acquired']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_owners');
        Schema::dropIfExists('registry_records');
    }
};
