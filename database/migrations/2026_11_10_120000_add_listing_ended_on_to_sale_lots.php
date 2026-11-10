<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a harvested classified ad was found to be gone from its site. A live ad is linked; a gone one is cited as text. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lots', fn (Blueprint $t) => $t->date('listing_ended_on')->nullable());
    }

    public function down(): void
    {
        Schema::table('sale_lots', fn (Blueprint $t) => $t->dropColumn('listing_ended_on'));
    }
};
