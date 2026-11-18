<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Event-level shared capacity across all ticket types; null = no event limit.
            $table->unsignedInteger('capacity')->nullable();
            // sold + held over all the event's ticket types. Maintained by TicketStock, and only while `capacity` is set.
            $table->unsignedInteger('reserved')->default(0);
        });

        DB::statement('ALTER TABLE events ADD CONSTRAINT events_reserved_check CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_capacity_check CHECK (capacity IS NULL OR (capacity >= 1 AND reserved <= capacity))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_capacity_check');
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_reserved_check');
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['capacity', 'reserved']);
        });
    }
};
