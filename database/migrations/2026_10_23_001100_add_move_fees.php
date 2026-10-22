<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fees are taken when money actually moves: cashing out of MFS (1%) or a
 * bank transfer (৳10), each as a "Bank charge" expense. A client paying by
 * MFS no longer carries a fee of its own, so the two are not counted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_moves', function (Blueprint $table) {
            $table->bigInteger('fee')->default(0)->after('amount');
            $table->foreignId('fee_entry_id')->nullable()->after('fee')->constrained('bdt_entries')->nullOnDelete();
        });

        DB::table('settings')->where('key', 'payment_fee_percent')->update(['value' => '0']);
    }

    public function down(): void
    {
        Schema::table('wallet_moves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_entry_id');
            $table->dropColumn('fee');
        });
    }
};
