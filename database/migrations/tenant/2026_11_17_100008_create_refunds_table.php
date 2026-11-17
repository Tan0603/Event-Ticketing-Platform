<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            // One refund per order, enforced by the database: a double click or a retried job cannot refund twice.
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->bigInteger('amount_minor');                    // integer minor units: what the buyer gets back
            $table->string('currency', 3);
            $table->string('reason', 500)->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete(); // null: refunded by an event cancellation
            $table->timestampsTz();

            // Reports group refunds by the day they happened.
            $table->index('created_at');
            $table->index('refunded_by');
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
