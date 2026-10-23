<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta's payment receipts, read from the receipts mailbox: each one is a
 * card charge, booked once (by its transaction id) with the split across
 * campaigns the receipt shows. The text is kept as proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 255)->unique();
            $table->string('transaction_id', 80)->nullable()->unique();
            $table->string('account_ref', 40)->nullable();
            $table->foreignId('pilot_account_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount')->nullable();
            $table->string('currency', 8)->nullable();
            $table->timestamp('charged_at')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->json('campaigns')->nullable();
            $table->string('subject', 255)->nullable();
            $table->mediumText('body')->nullable();
            $table->foreignId('usd_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('new');
            $table->string('problem', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_receipts');
    }
};
