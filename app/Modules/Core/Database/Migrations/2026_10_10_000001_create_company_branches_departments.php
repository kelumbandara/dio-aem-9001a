<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-tenant deployment: exactly one company row (id = 1).
        Schema::create('company_profile', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('registration_no', 50)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->default('LK');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->char('base_currency', 3)->default('LKR');
            $table->string('timezone', 64)->default('Asia/Colombo');
            $table->unsignedSmallInteger('fiscal_year_start_month')->default(4);
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE company_profile ADD CONSTRAINT company_profile_single_row CHECK (id = 1)');
        DB::statement('ALTER TABLE company_profile ADD CONSTRAINT company_profile_fiscal_month CHECK (fiscal_year_start_month BETWEEN 1 AND 12)');

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1); // optimistic concurrency
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('company_profile');
    }
};
