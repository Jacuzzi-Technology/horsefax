<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale history from published auction results (Richard, Oct 17 2026: Live Ag, Premier, Platinum; WSQHA show results
 * add registration numbers and foal years). Like the registered-name index, a lot is kept as the sale published it
 * and only attaches to a horse record on a careful match: registration number, or name + sire + dam, or name + foal
 * year + a parent. Weaker matches wait for staff. Prices are public once attached (Richard's decision, Oct 17 2026),
 * labeled by what the sale actually reported (Kate's rules): a reported sale is never made from a high bid or an
 * asking price. Buyer, consignor, agent and bidder names are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('data_source_id')->constrained();
            $table->foreignId('harvest_page_id')->nullable()->constrained();
            $table->string('external_id');                 // the sale's own lot or listing id
            $table->string('sale_name');
            $table->date('sale_date')->nullable();
            $table->string('date_precision')->default('day'); // day, month, year
            $table->smallInteger('sale_year')->nullable();
            $table->string('lot')->nullable();             // hip / lot number as published
            $table->string('horse_name');                  // as published
            $table->string('name_key');
            $table->boolean('registered')->nullable();
            $table->string('registry')->nullable();
            $table->string('registration_number')->nullable();
            $table->smallInteger('foal_year')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('sex')->nullable();
            $table->string('color')->nullable();
            $table->string('breed')->nullable();
            $table->string('sire_name')->nullable();
            $table->string('dam_name')->nullable();
            $table->string('dam_sire_name')->nullable();
            $table->json('pedigree')->nullable();          // fuller published pedigree, by position (s, ss, sd, d, ds ...)
            // sold = the sale reported it sold; high_bid = a bid was recorded but no sale confirmed; no_sale; catalog =
            // listed, no result yet; withdrawn.
            $table->string('status');
            $table->bigInteger('price_cents')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('buyers_premium')->default('unknown'); // included, excluded, none, unknown
            $table->string('note')->nullable();
            $table->string('source_url', 600);
            $table->foreignUuid('horse_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('horse_event_id')->nullable();
            $table->string('match_tier')->nullable();      // registration, sire_dam, foal_year_parent, staff
            $table->string('review_status')->nullable();   // queued, conflict, dismissed
            $table->string('review_note')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->unique(['data_source_id', 'external_id']);
            $table->index('name_key');
            $table->index('registration_number');
            $table->index(['horse_id', 'review_status']);
        });

        // WSQHA's results print the AQHA registration number and foal year beside the horse's name.
        Schema::table('show_appearances', function (Blueprint $table) {
            $table->string('registration_number')->nullable();
            $table->smallInteger('foal_year')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('show_appearances', fn (Blueprint $t) => $t->dropColumn(['registration_number', 'foal_year']));
        Schema::dropIfExists('sale_lots');
    }
};
