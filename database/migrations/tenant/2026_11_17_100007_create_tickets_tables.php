<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->unsignedSmallInteger('seq');            // 1..quantity within the order line
            $table->string('code', 32);                     // random, unguessable; this is what the QR code carries
            $table->string('status', 20)->default('valid');
            $table->timestampTz('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // Issuing is idempotent: running it twice for one order line cannot create a second ticket 1.
            $table->unique(['order_item_id', 'seq']);
            // The door scans a code: one lookup, and no two tickets can share a code.
            $table->unique('code');
            // Tickets of an order (e-mail, ticket page, refunds), also serves the FK.
            $table->index('order_id');
            // Check-in counts and "who is still valid" per event.
            $table->index(['event_id', 'status']);
            // FK lookups when a ticket type is touched.
            $table->index('ticket_type_id');
        });

        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('valid','used','void'))");
        // A ticket is used exactly when it has a check-in time.
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_used_check CHECK ((status = 'used') = (checked_in_at IS NOT NULL))");

        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            // Claim-first: whoever inserts this row owns sending that e-mail; nobody else can send it again.
            $table->unique(['order_id', 'type']);
        });

        DB::statement("ALTER TABLE order_notifications ADD CONSTRAINT order_notifications_status_check CHECK (status IN ('pending','sent','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
        Schema::dropIfExists('tickets');
    }
};
