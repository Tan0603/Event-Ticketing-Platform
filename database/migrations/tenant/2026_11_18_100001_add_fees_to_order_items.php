<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The booking fee is per ticket (spec section 5), so each line snapshots the fee of ONE ticket and of the whole line.
        Schema::table('order_items', function (Blueprint $table) {
            $table->bigInteger('unit_fee_minor')->default(0);
            $table->bigInteger('line_fee_minor')->default(0);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_fee_check CHECK (unit_fee_minor >= 0 AND line_fee_minor = unit_fee_minor * quantity)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_fee_check');
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_fee_minor', 'line_fee_minor']);
        });
    }
};
