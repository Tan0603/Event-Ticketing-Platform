<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->string('venue');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            // Public URL lookup, and two events can never share a slug even under concurrent creates.
            $table->unique('slug');
            // Public list: published events by start date; admin list filtered by status.
            $table->index(['status', 'starts_at']);
        });

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('draft','published','cancelled'))");
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_dates_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
