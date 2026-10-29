<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The IFCE page id (infochevaux.ifce.fr/fr/<slug>-<id>) of a French record, from API Équidés (Oct 27 2026). Later
 * extractions find the record by it instead of by name, foal year and sex, and new horses are recognised as new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horses', fn ($t) => $t->string('ifce_id', 32)->nullable());
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS horses_ifce_id ON horses (ifce_id) WHERE ifce_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS horses_ifce_id');
        Schema::table('horses', fn ($t) => $t->dropColumn('ifce_id'));
    }
};
