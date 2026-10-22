<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where BDT sits: each BDT row says which place (bank, bKash, cash) the
 * money came into or went out of, and moves between places are their own
 * rows, so every place has a balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bdt_entries', function (Blueprint $table) {
            $table->string('wallet', 10)->nullable()->after('pay_via');
        });

        Schema::create('wallet_moves', function (Blueprint $table) {
            $table->id();
            $table->date('moved_on');
            $table->string('from_wallet', 10);
            $table->string('to_wallet', 10);
            $table->bigInteger('amount');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_moves');

        Schema::table('bdt_entries', function (Blueprint $table) {
            $table->dropColumn('wallet');
        });
    }
};
