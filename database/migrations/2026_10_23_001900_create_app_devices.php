<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phones that run Klok Finance's own Android app: each one's Firebase
 * token under the person signed in there, so their notifications reach it.
 * A phone may also be the one that forwards the wallet SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->string('device', 250)->nullable();
            $table->unsignedSmallInteger('app_version')->default(0);
            $table->boolean('sms_forwarder')->default(false);
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_devices');
    }
};
