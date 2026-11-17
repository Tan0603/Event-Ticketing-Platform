<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            // The slug is the primary key: PK uniqueness is what makes racing sign-ups safe.
            $table->string('id', 30)->primary();
            $table->string('name');
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->unsignedInteger('fee_percent_bps'); // 500 = 5.00 %
            $table->unsignedInteger('fee_fixed_minor'); // minor units, e.g. 50 = 0.50
            $table->text('webhook_secret')->nullable(); // encrypted cast
            $table->text('failure_reason')->nullable();
            $table->jsonb('data')->nullable(); // stancl virtual column
            $table->timestampsTz();

            $table->index('status'); // scheduler fans out over active tenants
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('provisioning','active','failed'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_id_format_check CHECK (id ~ '^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$')");
        DB::statement('ALTER TABLE tenants ADD CONSTRAINT tenants_fees_check CHECK (fee_percent_bps >= 0 AND fee_fixed_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
