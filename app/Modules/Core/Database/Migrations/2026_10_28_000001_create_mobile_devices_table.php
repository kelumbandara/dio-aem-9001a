<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A phone or tablet a person has signed in on and allowed notifications for. The push address (an Expo token) is not a secret
        // that opens anything, but it is personal, so it is removed on sign-out and when the push service says the device is gone.
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();          // the sign-in (API token) this device belongs to
            $table->string('push_token', 255);
            $table->string('platform', 10);
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'push_token']);
            $table->index('token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
    }
};
