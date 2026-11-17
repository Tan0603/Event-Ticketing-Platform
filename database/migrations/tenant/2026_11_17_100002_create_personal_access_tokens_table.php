<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lives in the TENANT database: a token issued by tenant A does not exist in tenant B's
        // database, so "the header selects, it never grants" holds even before the ability check.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable'); // indexes (tokenable_type, tokenable_id): "revoke all tokens of a user"
            $table->text('name');
            $table->string('token', 64)->unique(); // sha256 hash lookup on every authenticated request
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index(); // `sanctum:prune-expired`
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
