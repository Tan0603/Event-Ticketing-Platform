<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100);          // the payment provider's own id for this notification
            $table->string('type', 40);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('outcome', 40);            // what we did with it (paid, duplicate, amount_mismatch ...)
            $table->jsonb('payload');
            $table->timestampsTz();

            // Exactly-once processing: a provider that retries or sends twice cannot make us act twice.
            $table->unique('event_id');
            // Everything the provider told us about one order.
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
