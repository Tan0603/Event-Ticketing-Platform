<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The id the payment provider echoes back in the webhook; the webhook finds the order by it.
            $table->string('payment_reference', 40)->nullable();
            // Late payment with no stock left: money taken, no tickets, the organizer must refund.
            $table->timestampTz('refund_pending_at')->nullable();
        });

        DB::statement("UPDATE orders SET payment_reference = 'pay_' || substr(md5(random()::text || id::text), 1, 12) WHERE payment_reference IS NULL");
        DB::statement('ALTER TABLE orders ALTER COLUMN payment_reference SET NOT NULL');
        // Webhook lookup by payment reference, and no two orders can ever share one.
        DB::statement('CREATE UNIQUE INDEX orders_payment_reference_unique ON orders (payment_reference)');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','paid','failed','expired','refund_pending','refunded'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','paid','failed','expired','refunded'))");
        DB::statement('DROP INDEX orders_payment_reference_unique');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_reference', 'refund_pending_at']);
        });
    }
};
