<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The "associate" kind was only a label; every one is a client now. */
    public function up(): void
    {
        DB::table('clients')->where('type', '!=', 'client')->update(['type' => 'client']);
    }

    public function down(): void {}
};
