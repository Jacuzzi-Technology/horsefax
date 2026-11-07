<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Breeding contacts (Richard, Oct 27 2026: "they probably would not mind having the contact information for breeding
 * services since that helps them get breeding contracts"). The phone and email the stallion page publishes for the
 * farm the horse stands at and for its breeding manager. Owners' own contacts are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stallion_services', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('website');
            $table->string('email')->nullable()->after('phone');
            $table->string('manager_name')->nullable()->after('email');
            $table->string('manager_phone', 40)->nullable()->after('manager_name');
            $table->string('manager_email')->nullable()->after('manager_phone');
        });
    }

    public function down(): void
    {
        Schema::table('stallion_services', fn (Blueprint $t) => $t->dropColumn(['phone', 'email', 'manager_name', 'manager_phone', 'manager_email']));
    }
};
