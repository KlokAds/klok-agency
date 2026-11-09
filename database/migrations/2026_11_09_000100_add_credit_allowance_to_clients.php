<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** How far past their credit a client's ads may run, paid from their next payment, and until when. */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('credit_allowance')->nullable()->after('commission_percent');
            $table->date('credit_allowance_until')->nullable()->after('credit_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['credit_allowance', 'credit_allowance_until']);
        });
    }
};
