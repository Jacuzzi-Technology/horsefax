<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sale calendar (Richard, Oct 24 2026: "check the sale calendar and schedule precise checks rather than weekly").
 * One row per upcoming or recent sale of a harvested source, with the dates as the source publishes them.
 * hf:sale-watch runs a source's harvester only when one of its sales is due a check: while the catalog is expected,
 * and for a few weeks after the sale while results may be posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40);                  // SaleWatch source key: pitzer, robinglenn, ...
            $table->string('key', 120);                    // the source's own id for the sale (catID, season-year, auction slug)
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('date_from', 255)->nullable();  // where the dates were read: a URL or "sale_lots"
            $table->timestamp('catalog_seen_at')->nullable();
            $table->timestamp('results_seen_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable(); // null: nothing more to check
            $table->timestamps();
            $table->unique(['source', 'key']);
            $table->index('next_check_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_events');
    }
};
