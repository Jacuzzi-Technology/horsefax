<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pedigrees go to 5 generations (positions like "sdsds"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedigree_entries', fn (Blueprint $t) => $t->string('position', 5)->change());
    }

    public function down(): void
    {
        Schema::table('pedigree_entries', fn (Blueprint $t) => $t->string('position', 3)->change());
    }
};
