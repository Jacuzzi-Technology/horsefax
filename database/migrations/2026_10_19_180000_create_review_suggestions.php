<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search-backed suggestions for staff (Richard, Oct 19 2026): for a possible duplicate pair or a sale lot waiting for
 * review, a web search finds pages; only pages from sources checked ALLOWED are fetched, and only facts read from them
 * (registration number, foal year, sex, sire, dam), each with its URL, are kept. Search results themselves (titles,
 * snippets, AI answers, other URLs) are never stored. Staff accept or reject; nothing is applied without that click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');             // name_review, sale_lot
            $table->string('subject_id');
            $table->string('suggestion');               // same_horse, different_horses, lot_belongs, unsure
            $table->foreignUuid('horse_id')->nullable()->constrained('horses'); // same_horse: the record to keep; lot_belongs: the record
            $table->text('summary');                    // Horsefax's own wording of why
            $table->json('evidence')->nullable();       // [{url, facts:{registration, foal_year, sex, sire, dam}, supports}]
            $table->smallInteger('searches')->default(0);
            $table->smallInteger('pages_fetched')->default(0);
            $table->decimal('cost_cents', 8, 2)->default(0);
            $table->string('status')->default('pending'); // pending, accepted, rejected (unsure ones stay pending)
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
            $table->index(['status', 'suggestion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_suggestions');
    }
};
