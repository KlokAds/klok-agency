<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The part of a payment that is the client's payment charge (bKash / QR %, NPSB fixed), not the invoice's. */
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->unsignedInteger('charge')->default(0)->after('amount');
            $table->unsignedBigInteger('charge_entry_id')->nullable()->after('fee_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn(['charge', 'charge_entry_id']);
        });
    }
};
