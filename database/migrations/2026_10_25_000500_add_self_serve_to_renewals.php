<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Chosen by the client on the portal: paid in full, it extends the campaign by itself. */
    public function up(): void
    {
        Schema::table('renewals', function (Blueprint $table) {
            $table->boolean('self_serve')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('renewals', function (Blueprint $table) {
            $table->dropColumn('self_serve');
        });
    }
};
