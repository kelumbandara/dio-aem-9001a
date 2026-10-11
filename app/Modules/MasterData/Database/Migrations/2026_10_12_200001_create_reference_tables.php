<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->unsignedTinyInteger('decimals')->default(0); // decimal places allowed in quantities
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE units_of_measure ADD CONSTRAINT units_of_measure_decimals_check CHECK (decimals BETWEEN 0 AND 6)');

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('item_categories')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name', 100);
            $table->string('symbol', 8)->nullable();
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE currencies ADD CONSTRAINT currencies_decimals_check CHECK (decimals BETWEEN 0 AND 4)');

        // Rate = how many units of the BASE currency one unit of this currency is worth, from effective_on.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->date('effective_on');
            $table->decimal('rate', 18, 8);
            $table->string('source', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['currency_id', 'effective_on']);
        });
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_positive_check CHECK (rate > 0)');

        // Tax codes are data, not code: rates change by law and are effective-dated.
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('kind', 12); // vat|sscl|nbt|stamp|withholding|exempt|other
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_kind_check CHECK (kind IN ('vat','sscl','nbt','stamp','withholding','exempt','other'))");

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('rate', 9, 4); // percent, e.g. 18.0000
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // null = open ended
            $table->timestamps();
            $table->index(['tax_code_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_range_check CHECK (rate >= 0 AND rate <= 100)');
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');

        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('days')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX currencies_one_base ON currencies (is_base) WHERE is_base');
        }
    }

    public function down(): void
    {
        foreach (['payment_terms', 'tax_rates', 'tax_codes', 'exchange_rates', 'currencies', 'item_categories', 'units_of_measure'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
