<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Kate's evidence model (Oct 16 2026). Every claim now carries two separate things:
 *  - source_type: who it came from (owner_reported, professional_attested, official_source, historical)
 *  - status: whether it has been checked (unverified, verified = "Horsefax Verified", disputed, superseded)
 * plus a verification record (who, when, how, scope, recheck date) and three dates: occurred, reported (created_at),
 * verified (verified_at). Identity facts (height, color, breed, ...) become claims with their own history.
 *
 * Old single labels convert without loss: self_reported and document_supplied become unverified (the attached
 * document still shows as evidence), independently_verified becomes verified.
 */
return new class extends Migration
{
    private const CLAIM_TABLES = ['horse_identifiers', 'horse_events', 'documents', 'pedigree_entries'];

    public function up(): void
    {
        foreach (self::CLAIM_TABLES as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('source_type')->default('owner_reported');
                $table->timestamp('verified_at')->nullable();
            });
            DB::table($t)->whereIn('status', ['self_reported', 'document_supplied'])->update(['status' => 'unverified']);
            DB::table($t)->where('status', 'independently_verified')->update(['status' => 'verified', 'verified_at' => DB::raw('updated_at')]);
            Schema::table($t, fn (Blueprint $table) => $table->string('status')->default('unverified')->change());
        }

        // Sources from who added each entry.
        $pro = ['veterinarian', 'farrier', 'trainer', 'breeder'];
        DB::table('horse_events')->whereIn('contributor_role', $pro)->update(['source_type' => 'professional_attested']);
        DB::table('horse_events')->where('contributor_role', 'staff')->update(['source_type' => 'historical']);
        DB::table('documents')->whereIn('contributor_role', $pro)->update(['source_type' => 'professional_attested']);
        // Lines copied into a pedigree-created record came from someone else's chart: third-party, historical.
        DB::table('pedigree_entries')->whereIn('horse_id', DB::table('horses')->where('source', 'pedigree')->select('id'))->update(['source_type' => 'historical']);
        DB::table('horse_identifiers')->whereIn('horse_id', DB::table('horses')->where('source', 'pedigree')->select('id'))->update(['source_type' => 'historical']);

        // Who verified what, how, and what the verification covers.
        Schema::create('verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type'); // HorseIdentifier, HorseEvent, Document, PedigreeEntry, HorseFact, Horse (identity)
            $table->uuid('subject_id');
            $table->string('method');       // registry_record, document_review, microchip_scan, physical_inspection, professional_confirmation, association_results, other
            $table->text('scope');          // what it covers, and what it does not
            $table->string('verifier_label')->default('Horsefax Verification Team');
            $table->foreignId('verified_by')->constrained('users'); // staff member (never shown publicly)
            $table->timestamp('verified_at');
            $table->date('recheck_on')->nullable();
            $table->uuid('evidence_document_id')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->text('revoked_reason')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        // Identity facts as claims with history. horses.* columns stay as the current value.
        Schema::create('horse_facts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('field');        // registered_name, barn_name, breed, sex, birth, color, markings, height
            $table->text('value')->nullable();
            $table->json('meta')->nullable(); // e.g. birth precision, height measured on
            $table->string('source_type')->default('owner_reported');
            $table->string('status')->default('unverified');
            $table->boolean('is_current')->default(true);
            $table->uuid('document_id')->nullable();
            $table->foreignId('contributor_id')->nullable()->constrained('users');
            $table->string('contributor_role')->nullable();
            $table->date('observed_on')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->uuid('superseded_by')->nullable();
            $table->timestamps();
            $table->index(['horse_id', 'field', 'is_current']);
        });

        // Verified professionals: credentials checked by Horsefax staff.
        Schema::table('users', function (Blueprint $table) {
            $table->string('credential_role')->nullable();
            $table->string('credential_detail')->nullable(); // license / membership reference, staff-entered
            $table->timestamp('credential_verified_at')->nullable();
        });

        // Backfill one current fact per identity field already on a horse.
        foreach (DB::table('horses')->get() as $h) {
            $source = $h->source === 'pedigree' ? 'historical' : 'owner_reported';
            $facts = [
                'registered_name' => [$h->registered_name, null],
                'barn_name' => [$h->barn_name, null],
                'breed' => [$h->breed, null],
                'sex' => [$h->sex, null],
                'birth' => [$h->birth_precision === 'day' ? $h->birth_date : ($h->birth_year ? (string) $h->birth_year : null), ['precision' => $h->birth_precision]],
                'color' => [$h->color, null],
                'markings' => [$h->markings, null],
                'height' => [$h->height_hands !== null ? (string) $h->height_hands : null, ['measured_on' => $h->height_measured_on]],
            ];
            foreach ($facts as $field => [$value, $meta]) {
                if ($value === null || $value === '') {
                    continue;
                }
                DB::table('horse_facts')->insert([
                    'id' => (string) Str::uuid(), 'horse_id' => $h->id, 'field' => $field, 'value' => $value,
                    'meta' => $meta ? json_encode($meta) : null, 'source_type' => $source, 'status' => 'unverified',
                    'is_current' => true, 'contributor_id' => $h->created_by, 'contributor_role' => $source === 'historical' ? null : 'owner',
                    'created_at' => $h->created_at, 'updated_at' => $h->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['credential_role', 'credential_detail', 'credential_verified_at']));
        Schema::dropIfExists('horse_facts');
        Schema::dropIfExists('verifications');
        foreach (self::CLAIM_TABLES as $t) {
            DB::table($t)->where('status', 'unverified')->update(['status' => 'self_reported']);
            DB::table($t)->where('status', 'verified')->update(['status' => 'independently_verified']);
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['source_type', 'verified_at']));
        }
    }
};
