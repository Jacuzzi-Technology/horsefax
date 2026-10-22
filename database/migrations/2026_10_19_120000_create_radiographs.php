<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiograph sets (Richard, Oct 19 2026): sale and pre-purchase radiographs kept as a dated, veterinarian-attributed
 * part of the horse's record, so sale companies don't have to handle them and buyers reach them from the catalog QR.
 * Private by default; the owner opens them to buyers on request, for a sale period, or through report links. Every
 * view is logged. Files live on the "radiographs" disk (the server for now; DigitalOcean Spaces later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiograph_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->date('taken_on')->nullable();
            $table->string('purpose')->nullable();        // pre_purchase, sale, routine, treatment, other
            $table->string('clinic')->nullable();
            $table->string('veterinarian')->nullable();
            $table->string('views')->nullable();          // "Front feet: lateral, DP; hocks"
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('source_type')->default('owner_reported'); // owner_reported, professional_attested
            $table->string('access')->default('private');  // private, on_request, sale
            $table->date('sale_access_until')->nullable();
            $table->boolean('in_reports')->default(false); // included in report links that include health
            $table->timestamps();
            $table->index(['horse_id', 'taken_on']);
        });
        Schema::create('radiograph_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('radiograph_set_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('kind');                       // image, dicom, pdf
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('path');
            $table->integer('position')->default(0);
            $table->timestamps();
        });
        Schema::create('radiograph_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('radiograph_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('requested'); // requested, approved, denied
            $table->text('message')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['radiograph_set_id', 'user_id']);
        });
        Schema::create('radiograph_views', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('radiograph_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('share_link_id')->nullable();
            $table->uuid('radiograph_file_id')->nullable();
            $table->string('action');                      // viewed, opened, downloaded
            $table->timestamp('created_at')->useCurrent();
            $table->index(['radiograph_set_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiograph_views');
        Schema::dropIfExists('radiograph_access');
        Schema::dropIfExists('radiograph_files');
        Schema::dropIfExists('radiograph_sets');
    }
};
