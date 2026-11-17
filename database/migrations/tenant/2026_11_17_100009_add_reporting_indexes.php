<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sales reports slice by the day an order was paid. Partial: pending, failed and expired orders never have it.
        DB::statement('CREATE INDEX orders_paid_at_idx ON orders (paid_at) WHERE paid_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_paid_at_idx');
    }
};
