<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A card charge can say what it paid for, as the platform's invoice does:
 * {"c:12": 700, "l:3": 1400} — campaign 12 and client 3's hand-written
 * spend. Whatever it does not say pays the oldest spend first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usd_entries', function (Blueprint $table) {
            $table->json('allocations')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('usd_entries', function (Blueprint $table) {
            $table->dropColumn('allocations');
        });
    }
};
