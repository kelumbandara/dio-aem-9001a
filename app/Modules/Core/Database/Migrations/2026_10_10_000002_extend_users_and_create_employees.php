<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Login accounts (users) are separate from HR records (employees).
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->string('user_type', 20)->default('internal')->index()->after('password');
            $table->boolean('is_active')->default(true)->index()->after('user_type');
            $table->foreignId('branch_id')->nullable()->after('is_active')->constrained('branches')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->after('branch_id')->constrained('departments')->restrictOnDelete();
            $table->string('locale', 5)->nullable()->after('department_id');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->softDeletes();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('employee_no', 30)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->text('nic')->nullable(); // encrypted cast; PII
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('designation', 100)->nullable();
            $table->date('hired_on')->nullable();
            $table->date('terminated_on')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mfa_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret'); // encrypted cast
            $table->text('recovery_codes')->nullable(); // encrypted JSON list of password hashes
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_timestep')->nullable(); // TOTP replay protection
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_secrets');
        Schema::dropIfExists('employees');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['public_id', 'user_type', 'is_active', 'locale', 'last_login_at', 'last_login_ip', 'deleted_at']);
        });
    }
};
