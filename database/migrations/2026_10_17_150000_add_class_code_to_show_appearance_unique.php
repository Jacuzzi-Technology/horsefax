<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Show offices reuse a class name under different class numbers (two "Small Fry W/T Equitation" classes), so the class number and go join the key. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_appearances', function (Blueprint $table) {
            $table->dropUnique(['harvest_page_id', 'show_record_id', 'event_name', 'class_name', 'placing']);
            $table->unique(['harvest_page_id', 'show_record_id', 'event_name', 'class_code', 'class_name', 'round', 'placing'], 'show_appearances_result_unique');
        });
    }

    public function down(): void
    {
        Schema::table('show_appearances', function (Blueprint $table) {
            $table->dropUnique('show_appearances_result_unique');
            $table->unique(['harvest_page_id', 'show_record_id', 'event_name', 'class_name', 'placing']);
        });
    }
};
