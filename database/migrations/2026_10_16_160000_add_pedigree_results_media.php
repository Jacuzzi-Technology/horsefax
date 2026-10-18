<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richard's data list (Oct 16 2026): pedigree, offspring, show record (patterned on Equistat), medical detail
 * (Coggins, health certificates, vaccinations, treatments), sale history, prior ownership as transactions, and
 * photos/videos entered by the current owner with releases.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pedigree, up to great-grandparents. Positions: s, d, ss, sd, ds, dd, sss, ssd, sds, sdd, dss, dsd, dds, ddd.
        // A line links to another Horsefax record when one matches; that link is what builds "offspring".
        Schema::create('pedigree_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('position', 3);
            $table->string('name');
            $table->string('registry')->nullable();
            $table->string('registration_number')->nullable();
            $table->smallInteger('foal_year')->nullable();
            $table->string('color')->nullable();
            $table->foreignUuid('linked_horse_id')->nullable()->constrained('horses')->nullOnDelete();
            $table->string('status')->default('self_reported');
            $table->string('visibility')->default('public');
            $table->foreignId('added_by')->constrained('users');
            $table->uuid('superseded_by')->nullable();
            $table->timestamps();
            $table->index(['horse_id', 'position']);
            $table->index('linked_horse_id');
        });

        Schema::table('horse_events', function (Blueprint $table) {
            $table->string('type')->nullable()->after('category'); // coggins, health_certificate, vaccination, treatment, exam, dental, farrier, deworming, sale, purchase, show, ...
            $table->string('result')->nullable();                  // e.g. Coggins negative
            $table->date('expires_on')->nullable();
            // Show record (Equistat-style)
            $table->string('organization')->nullable();            // AQHA, NRHA, NCHA, open show ...
            $table->string('event_name')->nullable();
            $table->string('class_name')->nullable();
            $table->string('placing')->nullable();
            $table->string('score')->nullable();
            $table->decimal('points', 8, 2)->nullable();
            $table->bigInteger('earnings_cents')->nullable();
            $table->string('rider')->nullable();                   // exhibitor
            $table->string('discipline')->nullable();              // event type: reining, cutting, ranch riding ...
            $table->string('division')->nullable();                // open, non-pro, amateur, youth, novice ...
            $table->string('round')->nullable();                   // go-round, finals ...
            // Sale history: price is private unless the owner publishes it separately.
            $table->bigInteger('amount_cents')->nullable();
            $table->string('amount_visibility')->default('private');
            $table->string('sale_venue')->nullable();               // auction / private sale / lot reference
        });

        // Photos and videos added by the owner. A release (photographer and anyone shown) is required to publish.
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('kind'); // photo, video, video_link
            $table->string('caption')->nullable();
            $table->date('taken_on')->nullable();
            $table->uuid('document_id')->nullable();   // uploaded file (stored like documents)
            $table->string('url')->nullable();         // YouTube / Vimeo link
            $table->boolean('rights_attested')->default(false);
            $table->uuid('release_document_id')->nullable();
            $table->string('visibility')->default('private');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::table('horse_events', fn (Blueprint $t) => $t->dropColumn(['type', 'result', 'expires_on', 'organization', 'event_name', 'class_name', 'placing', 'score', 'points', 'earnings_cents', 'rider', 'discipline', 'division', 'round', 'amount_cents', 'amount_visibility', 'sale_venue']));
        Schema::dropIfExists('pedigree_entries');
    }
};
