<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Two-letter search suggestions match the start of a name; this index keeps that fast across millions of records. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS horses_registered_name_prefix ON horses (lower(registered_name) text_pattern_ops)');
        DB::statement('ANALYZE horses');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS horses_registered_name_prefix');
    }
};
