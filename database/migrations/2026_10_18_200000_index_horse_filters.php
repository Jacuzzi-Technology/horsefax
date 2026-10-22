<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Staff filters on the Horses page (breed, sex, color, source) over 4M+ records. Built without locking the table. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['breed', 'sex', 'color', 'source'] as $col) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS horses_{$col}_filter ON horses ($col)");
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS horse_identifiers_registry_filter ON horse_identifiers (kind, registry, horse_id) WHERE superseded_by IS NULL');
    }

    public function down(): void
    {
        foreach (['breed', 'sex', 'color', 'source'] as $col) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS horses_{$col}_filter");
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS horse_identifiers_registry_filter');
    }
};
