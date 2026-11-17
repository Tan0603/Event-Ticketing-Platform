<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec section 7: ticket statuses are valid / checked_in / void (was valid / used / void).
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_used_check');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_status_check');
        DB::statement("UPDATE tickets SET status = 'checked_in' WHERE status = 'used'");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('valid','checked_in','void'))");
        // A ticket is checked in exactly when it has a check-in time.
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_checked_in_check CHECK ((status = 'checked_in') = (checked_in_at IS NOT NULL))");

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('gate_name', 60)->nullable(); // which entrance scanned it (optional)
            // Attendee list per event, filtered by ticket type and check-in status.
            $table->index(['event_id', 'ticket_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('gate_name');
        });
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_checked_in_check');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_status_check');
        DB::statement("UPDATE tickets SET status = 'used' WHERE status = 'checked_in'");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('valid','used','void'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_used_check CHECK ((status = 'used') = (checked_in_at IS NOT NULL))");
    }
};
