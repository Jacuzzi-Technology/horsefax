<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a record came from: an owner creating it, or a pedigree someone entered (no owner until claimed). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horses', function (Blueprint $table) {
            $table->string('source')->default('owner')->after('species'); // owner | pedigree
            $table->uuid('source_horse_id')->nullable()->after('source');  // the chart it was first entered in
        });
    }

    public function down(): void
    {
        Schema::table('horses', fn (Blueprint $t) => $t->dropColumn(['source', 'source_horse_id']));
    }
};
