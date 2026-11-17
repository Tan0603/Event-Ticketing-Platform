<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec section 2: an event has venue_name and venue_address.
        Schema::table('events', function (Blueprint $table) {
            $table->renameColumn('venue', 'venue_name');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->string('venue_address', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('venue_address');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->renameColumn('venue_name', 'venue');
        });
    }
};
