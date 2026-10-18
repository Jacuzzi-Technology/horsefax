<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** As-you-type search: a trigram index so "lark" finds "Juniper Creek Lark" quickly across a very large table. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS horses_registered_name_trgm ON horses USING gin (lower(registered_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS horses_barn_name_trgm ON horses USING gin (lower(barn_name) gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS horses_registered_name_trgm');
        DB::statement('DROP INDEX IF EXISTS horses_barn_name_trgm');
    }
};
