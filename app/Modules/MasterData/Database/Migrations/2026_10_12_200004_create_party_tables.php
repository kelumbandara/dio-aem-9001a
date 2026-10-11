<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('kind', 10)->default('company'); // company|individual
            $table->string('tax_id', 40)->nullable()->index(); // VAT / TIN
            $table->string('business_reg_no', 40)->nullable();
            $table->text('nic')->nullable(); // encrypted cast; individuals only
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->foreignId('payment_terms_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();
            $table->decimal('credit_limit', 18, 4)->nullable(); // null = not set
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index('name');
        });
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_kind_check CHECK (kind IN ('company','individual'))");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_check CHECK (credit_limit IS NULL OR credit_limit >= 0)');

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('kind', 10)->default('company');
            $table->string('tax_id', 40)->nullable()->index();
            $table->string('business_reg_no', 40)->nullable();
            $table->text('nic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->foreignId('payment_terms_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete(); // purchase list
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index('name');
        });
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_kind_check CHECK (kind IN ('company','individual'))");

        foreach (['customer', 'supplier'] as $party) {
            $parent = $party.'s';

            Schema::create("{$party}_contacts", function (Blueprint $table) use ($party, $parent) {
                $table->id();
                $table->foreignId("{$party}_id")->constrained($parent)->restrictOnDelete();
                $table->string('name', 150);
                $table->string('job_title', 100)->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 30)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->index("{$party}_id");
            });

            Schema::create("{$party}_addresses", function (Blueprint $table) use ($party, $parent) {
                $table->id();
                $table->foreignId("{$party}_id")->constrained($parent)->restrictOnDelete();
                $table->string('type', 10)->default('billing'); // billing|shipping|other
                $table->string('line1');
                $table->string('line2')->nullable();
                $table->string('city', 100)->nullable();
                $table->string('district', 100)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->char('country', 2)->default('LK');
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->index("{$party}_id");
            });
            DB::statement("ALTER TABLE {$party}_addresses ADD CONSTRAINT {$party}_addresses_type_check CHECK (type IN ('billing','shipping','other'))");
        }

        // Account numbers are encrypted at rest; changes can be put behind an approval policy.
        Schema::create('supplier_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('bank_name', 150);
            $table->string('branch_name', 150)->nullable();
            $table->string('account_name', 150);
            $table->text('account_no'); // encrypted cast
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('supplier_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX supplier_bank_accounts_one_default ON supplier_bank_accounts (supplier_id) WHERE is_default AND is_active');
        }
    }

    public function down(): void
    {
        foreach (['supplier_bank_accounts', 'supplier_addresses', 'supplier_contacts', 'customer_addresses', 'customer_contacts', 'suppliers', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
