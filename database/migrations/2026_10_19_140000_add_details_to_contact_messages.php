<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Partnership inquiries (Partners page, Oct 19 2026) land in the same staff inbox, with their organization fields here. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_messages', fn (Blueprint $t) => $t->json('details')->nullable());
    }

    public function down(): void
    {
        Schema::table('contact_messages', fn (Blueprint $t) => $t->dropColumn('details'));
    }
};
