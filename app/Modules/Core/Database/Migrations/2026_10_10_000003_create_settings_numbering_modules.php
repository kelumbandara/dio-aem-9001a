<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150);
            $table->string('scope', 20)->default('global');
            $table->unsignedBigInteger('scope_id')->default(0); // 0 = not scoped (keeps the unique index NULL-free)
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['key', 'scope', 'scope_id']);
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('format', 100)->default('{SEQ}');
            $table->unsignedSmallInteger('padding')->default(5);
            $table->string('reset_policy', 20)->default('never'); // never|yearly|fiscal_yearly|monthly
            $table->boolean('per_branch')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One counter per sequence and period/branch bucket; incremented under a row lock.
        Schema::create('number_sequence_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('number_sequences')->restrictOnDelete();
            $table->string('bucket', 60);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['sequence_id', 'bucket']);
        });

        Schema::create('module_settings', function (Blueprint $table) {
            $table->id();
            $table->string('module', 50)->unique();
            $table->boolean('enabled');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_settings');
        Schema::dropIfExists('number_sequence_counters');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('settings');
    }
};
