<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Search suggestions look up the years a horse was shown by its record. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_records', fn (Blueprint $t) => $t->index('horse_id'));
    }

    public function down(): void
    {
        Schema::table('show_records', fn (Blueprint $t) => $t->dropIndex(['horse_id']));
    }
};
