<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stronger ownership verification (Richard, Oct 18 2026; built ahead of partners):
 *  - claim codes: one-time codes a seller or sale company hands the buyer (printed with a QR on a claim certificate);
 *    redeeming one makes the buyer the verified owner when nobody else is;
 *  - seller confirmations: a claim can name the seller, who gets a link to confirm or deny the sale;
 *  - identity checks: staff check a person's ID once; the ID image is deleted when the check is decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();   // sha256 of the normalized code
            $table->text('code_encrypted');               // so staff can reprint the certificate
            $table->string('issuer_name');                // "Horse of My Dreams", "Billings Livestock"
            $table->foreignUuid('sale_lot_id')->nullable()->constrained('sale_lots')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('users');
            $table->timestamp('redeemed_at')->nullable();
            $table->string('redemption')->nullable();     // verified_owner, sent_to_review
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['horse_id', 'redeemed_at']);
        });

        Schema::create('claim_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_item_id')->constrained()->cascadeOnDelete();
            $table->string('seller_name');
            $table->string('seller_email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->string('response')->nullable();       // confirmed, denied
            $table->text('response_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('identity_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('document_path')->nullable();  // private; deleted once decided
            $table->string('status')->default('pending'); // pending, verified, rejected
            $table->string('method')->nullable();         // id_document, video_call, third_party
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('document_deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('identity_verified_at')->nullable();
            $table->string('identity_verified_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['identity_verified_at', 'identity_verified_name']));
        Schema::dropIfExists('identity_checks');
        Schema::dropIfExists('claim_confirmations');
        Schema::dropIfExists('claim_codes');
    }
};
