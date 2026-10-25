<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Client portal: a person who signs up is a "Client" user, and sees their client's
     * campaigns and invoices once the Owner links them to that client (client_id).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('company', 120)->nullable()->after('phone');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Client', 'web');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['phone', 'company']);
        });
    }
};
