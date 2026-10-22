<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices the agency sends its clients, the payments made against them
 * (wallet sessions, TrxID claims, manual entries) and the incoming-money
 * SMS the wallet phone forwards to confirm them. Money is poisha, USD cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('seq')->unique();
            $table->string('number', 20)->unique();
            $table->foreignId('client_id')->constrained('clients');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('status', 12)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->string('public_token', 40)->unique();
            $table->bigInteger('total')->default(0);
            $table->bigInteger('usd')->default(0);
            $table->bigInteger('paid')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('kind', 12);
            $table->string('description');
            $table->bigInteger('usd')->nullable();
            $table->decimal('rate', 10, 4)->nullable();
            $table->bigInteger('amount');
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('method', 12);
            $table->bigInteger('amount');
            $table->string('status', 10)->default('pending')->index();
            $table->string('reference', 120)->nullable();
            $table->string('sender', 120)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('wallet', 20)->nullable();
            $table->bigInteger('usd_credit')->nullable();
            $table->foreignId('bdt_entry_id')->nullable()->constrained('bdt_entries')->nullOnDelete();
            $table->foreignId('fee_entry_id')->nullable()->constrained('bdt_entries')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['method', 'status', 'amount']);
        });

        Schema::create('payment_sms', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 12);
            $table->string('sender', 60);
            $table->text('body');
            $table->bigInteger('amount')->nullable();
            $table->string('trx_id', 40)->nullable();
            $table->string('payer_number', 30)->nullable();
            $table->string('status', 12)->default('unmatched')->index();
            $table->foreignId('invoice_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['provider', 'trx_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sms');
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
