<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);                          // stored UPPERCASE
            $table->string('discount_type', 10);                 // percent | fixed
            $table->unsignedInteger('percent_bps')->nullable();  // 1..10000, when percent
            $table->bigInteger('amount_minor')->nullable();      // > 0, when fixed
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete(); // null = every event
            $table->unsignedInteger('max_uses')->nullable();     // null = unlimited
            $table->unsignedInteger('used')->default(0);         // changed ONLY by atomic conditional UPDATE
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Checkout looks a code up by its text; two codes can never share one, even when two admins race.
            $table->unique('code');
            // Codes of one event (admin filter) and the FK.
            $table->index('event_id');
        });

        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_code_upper_check CHECK (code = upper(code))');
        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_used_check CHECK (used >= 0)');
        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_max_uses_check CHECK (max_uses IS NULL OR (max_uses >= 1 AND used <= max_uses))');
        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_type_value_check CHECK (
            (discount_type = 'percent' AND percent_bps BETWEEN 1 AND 10000 AND amount_minor IS NULL)
            OR (discount_type = 'fixed' AND amount_minor > 0 AND percent_bps IS NULL))");
        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_window_check CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
