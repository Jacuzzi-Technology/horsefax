<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: professional contributors. An owner invites a veterinarian, farrier, trainer or breeder by email; the
 * professional accepts with their own Horsefax account and can then add history entries and documents in their own
 * name. Rows are never deleted: a revoked contributor keeps the row (and every entry they added).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // veterinarian, farrier, trainer, breeder
            $table->string('email');
            $table->foreignId('user_id')->nullable()->constrained(); // set when accepted
            $table->foreignId('invited_by')->constrained('users');
            $table->string('status')->default('pending'); // pending, active, declined, revoked
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['horse_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // Who uploaded a document, in which capacity (null = a steward of the horse).
        Schema::table('documents', function (Blueprint $table) {
            $table->string('contributor_role')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('contributor_role'));
        Schema::dropIfExists('contributors');
    }
};
