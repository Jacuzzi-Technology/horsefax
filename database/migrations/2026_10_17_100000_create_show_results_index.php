<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The registered-name index (Richard, Oct 16 2026): accumulate horses' registered names and published show results
 * from public sources that don't forbid it, before any registry partnership. A name entry is NOT a horse record:
 * results stay "unmatched" until staff confirm which horse they belong to (names repeat across horses).
 * Only the horse's name and its results are kept; owner and exhibitor names are not stored (youth riders are
 * minors). Each harvested page is kept privately as evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // congress
            $table->string('name');
            $table->string('url');
            $table->string('permission')->default('no_terms_posted'); // no_terms_posted, requested, granted, declined
            $table->text('terms_note')->nullable();
            $table->string('contact')->nullable();
            $table->timestamps();
        });

        Schema::create('harvest_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained();
            $table->string('url', 600)->unique();
            $table->string('kind');                    // index, class
            $table->smallInteger('year')->nullable();
            $table->string('status')->default('queued'); // queued, fetched, parsed, skipped, failed
            $table->smallInteger('http_status')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('path')->nullable();        // private evidence copy
            $table->integer('rows')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->index(['data_source_id', 'status']);
        });

        Schema::create('show_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_key')->unique();      // normalized registered name
            $table->string('display_name');
            $table->string('registry_inferred')->nullable(); // AQHA when seen in AQHA classes
            $table->smallInteger('first_year')->nullable();
            $table->smallInteger('last_year')->nullable();
            $table->integer('appearances')->default(0);
            $table->bigInteger('earnings_cents')->default(0);
            $table->foreignUuid('horse_id')->nullable()->constrained()->nullOnDelete(); // set once staff match it
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->index('display_name');
        });

        Schema::create('show_appearances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('show_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->constrained();
            $table->foreignId('harvest_page_id')->constrained();
            $table->string('horse_name');              // as printed
            $table->string('event_name');
            $table->smallInteger('event_year');
            $table->string('event_dates')->nullable();
            $table->string('class_code')->nullable();
            $table->string('class_name');
            $table->string('round')->nullable();       // go 1, final ...
            $table->string('organization')->nullable(); // AQHA, NRHA, NSBA, Congress stakes
            $table->string('registry_inferred')->nullable();
            $table->string('placing')->nullable();
            $table->bigInteger('earnings_cents')->nullable();
            $table->string('source_url', 600);
            $table->uuid('horse_event_id')->nullable(); // once attached to a horse record
            $table->timestamps();
            $table->unique(['harvest_page_id', 'show_record_id', 'placing']);
            $table->index(['show_record_id', 'event_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_appearances');
        Schema::dropIfExists('show_records');
        Schema::dropIfExists('harvest_pages');
        Schema::dropIfExists('data_sources');
    }
};
