<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Horsefax record. Every fact keeps who supplied it, how it is known (verification status) and who may see it
 * (visibility). History is append-only: corrections supersede rows, they never overwrite them.
 *
 * Visibility: private (stewards + staff), shared (also in share links whose scope includes the section), public (also on
 * the public summary when the owner has turned it on).
 * Verification: self_reported, document_supplied, independently_verified, disputed, superseded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false);
            $table->string('standing')->default('good'); // good | suspended
        });

        Schema::create('horses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('horsefax_id', 16)->unique(); // HF-XXXX-XXXX, never reused
            $table->string('species')->default('horse');
            $table->string('barn_name')->nullable();
            $table->string('registered_name')->nullable();
            $table->string('breed')->nullable();
            $table->string('sex')->nullable(); // mare, stallion, gelding, filly, colt; null = unknown
            $table->date('birth_date')->nullable();
            $table->smallInteger('birth_year')->nullable();
            $table->string('birth_precision')->default('unknown'); // day, year, unknown
            $table->string('color')->nullable();
            $table->text('markings')->nullable();
            $table->decimal('height_hands', 4, 1)->nullable();
            $table->date('height_measured_on')->nullable();
            // Identity starts unverified; staff mark it verified after cross-checking papers/registry/microchip.
            $table->string('identity_status')->default('unverified'); // unverified | under_review | verified
            $table->boolean('listed')->default(true);          // basic identity appears in Horsefax search
            $table->boolean('public_summary')->default(false); // full public summary page
            $table->uuid('profile_photo_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('registered_name');
            $table->index('barn_name');
        });

        Schema::create('horse_names', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind'); // previous, alias
            $table->string('visibility')->default('shared');
            $table->foreignId('added_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('horse_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // registration, microchip, other
            $table->string('registry')->nullable(); // AQHA, APHA, Jockey Club, ...
            $table->string('value');
            $table->string('value_normalized');
            $table->string('status')->default('self_reported');
            $table->string('visibility'); // microchips private by default
            $table->uuid('document_id')->nullable();
            $table->boolean('in_conflict')->default(false);
            $table->foreignId('added_by')->constrained('users');
            $table->uuid('superseded_by')->nullable();
            $table->timestamps();
            $table->index(['kind', 'value_normalized']);
        });

        Schema::create('stewardships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('role'); // owner, custodian
            $table->string('status'); // asserted, verified, ended
            $table->date('started_on')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('kind'); // registration_papers, coggins, health_certificate, vet_record, bill_of_sale, photo, other
            $table->string('title');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('issuer')->nullable();
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('path');
            $table->string('status')->default('document_supplied');
            $table->string('visibility')->default('private');
            $table->timestamps();
        });

        Schema::create('horse_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // health, care, training, competition, ownership, transport, other
            $table->string('title');
            $table->date('occurred_on')->nullable();
            $table->string('date_precision')->default('day'); // day, month, year, unknown
            $table->text('details')->nullable();
            $table->string('provider')->nullable(); // vet, farrier, trainer, show ... as named by the contributor
            $table->foreignId('contributor_id')->constrained('users');
            $table->string('contributor_role'); // owner, custodian, staff
            $table->string('status')->default('self_reported');
            $table->string('visibility');
            $table->uuid('document_id')->nullable();
            $table->uuid('supersedes')->nullable();
            $table->uuid('superseded_by')->nullable();
            $table->timestamps();
            $table->index(['horse_id', 'occurred_on']);
        });

        Schema::create('share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            $table->json('sections');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });

        // Staff work list: ownership claims, identifier conflicts, corrections/disputes, transfers needing a look.
        Schema::create('review_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind'); // ownership_claim, identifier_conflict, dispute
            $table->foreignUuid('horse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->string('submitter_name')->nullable();
            $table->string('submitter_email')->nullable();
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->text('statement')->nullable();
            $table->json('details')->nullable();
            $table->string('status')->default('open'); // open, approved, rejected
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'kind']);
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users');
            $table->string('to_email');
            $table->foreignId('to_user_id')->nullable()->constrained('users');
            $table->string('status')->default('pending'); // pending, accepted, declined, cancelled
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('topic');
            $table->text('message');
            $table->string('status')->default('new');
            $table->timestamps();
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->uuid('horse_id')->nullable()->index();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // The audit log is append-only at the database level too.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER audit_log_no_change BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_change ON audit_log; DROP FUNCTION IF EXISTS audit_log_append_only();');
        foreach (['audit_log', 'contact_messages', 'transfers', 'review_items', 'share_links', 'horse_events', 'documents',
            'stewardships', 'horse_identifiers', 'horse_names', 'horses'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_staff', 'standing']));
    }
};
