<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            // Tier progression: this tier opens once the referenced tier is sold out or its sales ended.
            $table->foreignId('unlocks_after_ticket_type_id')->nullable()->constrained('ticket_types')->nullOnDelete();

            // The hold UPDATE looks the predecessor up by id; also serves the FK (what depends on this tier).
            $table->index('unlocks_after_ticket_type_id');
        });

        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_not_own_predecessor_check CHECK (unlocks_after_ticket_type_id IS NULL OR unlocks_after_ticket_type_id <> id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_types DROP CONSTRAINT ticket_types_not_own_predecessor_check');
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unlocks_after_ticket_type_id');
        });
    }
};
