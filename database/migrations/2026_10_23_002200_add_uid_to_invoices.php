<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Each invoice's own code for its admin address (/invoices/01jb…), so the
 * address does not show how many invoices there are, as /invoices/2 did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('uid', 26)->nullable()->unique()->after('id');
        });

        foreach (DB::table('invoices')->whereNull('uid')->pluck('id') as $id) {
            DB::table('invoices')->where('id', $id)->update(['uid' => strtolower((string) Str::ulid())]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['uid']);
            $table->dropColumn('uid');
        });
    }
};
