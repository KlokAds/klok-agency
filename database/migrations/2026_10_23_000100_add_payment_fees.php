<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every client payment loses a small transfer fee (0.1% by default).
 * The fee is its own expense row, tied to the payment it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bdt_entries', function (Blueprint $table) {
            $table->foreignId('fee_for_id')->nullable()->after('fx_batch_id')->constrained('bdt_entries')->nullOnDelete();
        });

        if (! DB::table('expense_heads')->where('name', 'Payment fee')->exists()) {
            DB::table('expense_heads')->insert(['name' => 'Payment fee', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('bdt_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('fee_for_id'));
    }
};
