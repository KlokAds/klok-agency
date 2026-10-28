<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Owner clears the phone's messages from the Payment SMS list on the web. A cleared one only
 * leaves the list: an SMS that paid an invoice, or a card line in the books, stays for the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_sms', 'card_events'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('cleared_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        foreach (['payment_sms', 'card_events'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['cleared_at']);
                $table->dropColumn('cleared_at');
            });
        }
    }
};
