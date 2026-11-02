<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every merge, with what it moved, so it can be undone (Nov 2 2026: merges now run automatically under rules).
 * name_reviews.rule is the rule that sorted an open pair (NamePairRules): which ones merge without a staff click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horse_merges', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('kept_horse_id')->constrained('horses');
            $t->foreignUuid('gone_horse_id')->constrained('horses');
            $t->string('rule', 40)->nullable();
            $t->string('batch', 40)->nullable()->index();
            $t->foreignId('merged_by')->constrained('users');
            $t->jsonb('log');
            $t->timestamp('undone_at')->nullable();
            $t->timestamps();
            $t->index('gone_horse_id');
        });
        Schema::table('name_reviews', fn (Blueprint $t) => $t->string('rule', 40)->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('name_reviews', fn (Blueprint $t) => $t->dropColumn('rule'));
        Schema::dropIfExists('horse_merges');
    }
};
