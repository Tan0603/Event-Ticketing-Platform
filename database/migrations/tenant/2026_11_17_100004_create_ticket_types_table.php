<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('price_minor');            // integer minor units, never a float
            $table->unsignedInteger('quantity');          // total capacity
            $table->unsignedInteger('sold')->default(0);  // paid; changed only by atomic conditional UPDATE
            $table->unsignedInteger('held')->default(0);  // reserved by pending orders; same rule
            $table->unsignedInteger('max_per_order');
            $table->timestampTz('sales_start_at')->nullable();
            $table->timestampTz('sales_end_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            // Listing tiers of an event in display order (also serves the FK lookup).
            $table->index(['event_id', 'sort_order', 'id']);
        });

        // The last line of defence against overselling: even a buggy code path cannot break it.
        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_capacity_check CHECK (sold + held <= quantity)');
        // Postgres has no unsigned integers: forbid negative counters explicitly.
        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_non_negative_check CHECK (quantity >= 0 AND sold >= 0 AND held >= 0)');
        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_price_check CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_max_per_order_check CHECK (max_per_order >= 1)');
        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_sales_window_check CHECK (sales_start_at IS NULL OR sales_end_at IS NULL OR sales_end_at > sales_start_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
    }
};
