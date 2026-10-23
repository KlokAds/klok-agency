<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick entries written with no internet carry their own id; each id is
 * booked once, so a resend after a dropped answer never books it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_entries');
    }
};
