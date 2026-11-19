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
            $table->bigInteger('discount_minor')->default(0);
            // The code that was applied. The text is a snapshot: it survives the promo code being renamed or deleted.
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->string('promo_code', 40)->nullable();

            // Orders that used a code (give a use back on expiry, "who used this code").
            $table->index('promo_code_id');
        });

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_money_check');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (
            subtotal_minor >= 0 AND fee_minor >= 0
            AND discount_minor >= 0 AND discount_minor <= subtotal_minor
            AND total_minor = subtotal_minor - discount_minor + fee_minor)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_code_check CHECK (discount_minor = 0 OR promo_code IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_discount_code_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_money_check');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (subtotal_minor >= 0 AND fee_minor >= 0 AND total_minor = subtotal_minor + fee_minor)');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn(['discount_minor', 'promo_code']);
        });
    }
};
