<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20);
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('status', 20);
            $table->char('currency', 3);
            // Money snapshot: never recomputed from ticket_types or the tenant settings.
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('fee_minor');
            $table->bigInteger('total_minor');
            $table->integer('fee_percent_bps');
            $table->integer('fee_fixed_minor');
            $table->timestampTz('expires_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestampsTz();

            // Lookup by the public reference; the reference is the buyer's capability to see the order.
            $table->unique('reference');
            // A retried checkout with the same key can never create a second order (NULLs do not collide).
            $table->unique('idempotency_key');
            // Orders of an event by status (dashboard, reports, "has orders" check).
            $table->index(['event_id', 'status']);
            // Orders of one buyer.
            $table->index('buyer_email');
        });

        // The expiry job only ever looks at pending orders, so index only those: tiny and always hot.
        DB::statement("CREATE INDEX orders_pending_expiry_idx ON orders (expires_at) WHERE status = 'pending'");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','paid','failed','expired','refunded'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (subtotal_minor >= 0 AND fee_minor >= 0 AND total_minor = subtotal_minor + fee_minor)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_email_lowercase_check CHECK (buyer_email = lower(buyer_email))');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->string('ticket_type_name');         // snapshot
            $table->bigInteger('unit_price_minor');     // snapshot
            $table->integer('quantity');
            $table->bigInteger('line_total_minor');
            $table->timestampsTz();

            // Items of an order (every order read), also serves the FK.
            $table->index('order_id');
            // Which orders bought a ticket type (blocks deleting a sold tier, sales per tier reports).
            $table->index('ticket_type_id');
            // One line per ticket type per order.
            $table->unique(['order_id', 'ticket_type_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_money_check CHECK (unit_price_minor >= 0 AND line_total_minor = unit_price_minor * quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
