<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A money SMS keeps the service centre (SMSC) that delivered it and the balance it shows, and
 * whether it was held for the Owner because it did not look like the sender's genuine SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sms', function (Blueprint $table) {
            $table->string('service_centre', 40)->nullable()->after('payer_number');
            $table->bigInteger('balance')->nullable()->after('service_centre');
            $table->boolean('held')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sms', fn (Blueprint $table) => $table->dropColumn(['service_centre', 'balance', 'held']));
    }
};
