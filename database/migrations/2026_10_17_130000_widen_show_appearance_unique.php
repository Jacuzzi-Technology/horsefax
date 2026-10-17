<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One NRCHA source file holds every class at a show, so a result is unique per page, horse, class and placing. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_appearances', function (Blueprint $table) {
            $table->dropUnique(['harvest_page_id', 'show_record_id', 'placing']);
            $table->unique(['harvest_page_id', 'show_record_id', 'class_name', 'placing']);
        });
    }

    public function down(): void
    {
        Schema::table('show_appearances', function (Blueprint $table) {
            $table->dropUnique(['harvest_page_id', 'show_record_id', 'class_name', 'placing']);
            $table->unique(['harvest_page_id', 'show_record_id', 'placing']);
        });
    }
};
