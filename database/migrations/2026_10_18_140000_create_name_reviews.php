<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Possible duplicate names (Richard, Oct 18 2026): records whose names differ only by spacing/punctuation or by a
 * letter or two. Many are different horses (registries don't allow duplicates, so owners pick near names), so staff
 * decide each pair, often from a registry name lookup. A misspelled record is merged into the right one and kept
 * as a pointer (records are never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horses', function (Blueprint $table) {
            $table->uuid('merged_into_id')->nullable()->index();
            $table->timestamp('merged_at')->nullable();
        });
        Schema::create('name_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('horse_a_id')->constrained('horses');
            $table->foreignUuid('horse_b_id')->constrained('horses');
            $table->string('match');                 // same_letters, near
            $table->smallInteger('priority');        // lower first
            $table->string('status')->default('open'); // open, different_horses, merged, closed
            $table->foreignUuid('kept_horse_id')->nullable()->constrained('horses');
            $table->string('registration_number')->nullable(); // what the registry lookup returned
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['horse_a_id', 'horse_b_id']);
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('name_reviews');
        Schema::table('horses', fn (Blueprint $t) => $t->dropColumn(['merged_into_id', 'merged_at']));
    }
};
