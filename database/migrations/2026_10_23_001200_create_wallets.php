<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The places BDT sits in become a list the owner keeps: more than one bank
 * account or bKash number, cash, and money held aside. Each has a type that
 * decides what moving money out of it costs, and an opening balance as of
 * the day the places were first counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('name', 80);
            $table->string('type', 10);
            $table->string('detail', 120)->nullable();
            $table->bigInteger('opening_balance')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('wallets')->insert([
            ['key' => 'bank', 'name' => 'Bank', 'type' => 'bank', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'bkash', 'name' => 'bKash', 'type' => 'mfs', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'cash', 'name' => 'Cash', 'type' => 'cash', 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
