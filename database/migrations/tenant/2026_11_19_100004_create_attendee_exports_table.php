<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendee_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->string('path')->nullable();              // exports/{tenant id}/{uuid}.csv on the exports disk
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            // Exports of an event, newest first (serves the FK too).
            $table->index(['event_id', 'id']);
            // Serves the requested_by FK.
            $table->index('requested_by');
        });

        DB::statement("ALTER TABLE attendee_exports ADD CONSTRAINT attendee_exports_status_check CHECK (status IN ('pending','processing','ready','failed'))");
        DB::statement("ALTER TABLE attendee_exports ADD CONSTRAINT attendee_exports_ready_check CHECK (status <> 'ready' OR (path IS NOT NULL AND row_count IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendee_exports');
    }
};
