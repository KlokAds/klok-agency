<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid campaigns stop when the client's money does: each campaign keeps
 * what Meta says about its budget, spend cap and end, and each day the
 * budget it had, so spend can be held against it. A renewal is the link a
 * client gets before a campaign ends, to pay for more days or to stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->bigInteger('budget')->nullable()->after('daily_budget');
            $table->bigInteger('spend_cap')->nullable()->after('budget');
            $table->bigInteger('spent_before')->nullable()->after('spend_cap');
            $table->timestamp('limits_at')->nullable()->after('spent_before');
            $table->string('limits_error', 300)->nullable()->after('limits_at');
            $table->timestamp('paused_for_credit_at')->nullable()->after('limits_error');
        });

        Schema::table('campaign_days', function (Blueprint $table) {
            $table->bigInteger('budget')->nullable()->after('spend');
        });

        Schema::create('renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('token', 48)->unique();
            $table->date('ends_on');
            $table->bigInteger('budget')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->bigInteger('usd')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->date('new_end')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error', 300)->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewals');

        Schema::table('campaign_days', function (Blueprint $table) {
            $table->dropColumn('budget');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['budget', 'spend_cap', 'spent_before', 'limits_at', 'limits_error', 'paused_for_credit_at']);
        });
    }
};
