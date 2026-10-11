<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money accounts of the company (banks, petty cash, cash in hand): each points at a ledger account.
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('kind', 10)->default('bank');         // bank|cash
            $table->string('bank_name', 150)->nullable();
            $table->text('account_no')->nullable();              // encrypted
            $table->string('currency', 3)->default('LKR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_kind_check CHECK (kind IN ('bank','cash'))");

        // Invoices owed by customers (receivable) and bills owed to suppliers (payable), as posted by Sales/Procurement.
        Schema::create('open_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('side', 10);                           // receivable|payable
            $table->unsignedBigInteger('party_id');               // customers.id or suppliers.id
            $table->string('document_no', 60);                    // the invoice/bill number of the source document
            $table->date('document_date');
            $table->date('due_date');
            $table->decimal('amount', 18, 4);
            $table->decimal('open_amount', 18, 4);                // amount less what receipts/payments have settled
            $table->string('source_type', 60)->nullable();
            $table->string('source_id', 60)->nullable();
            $table->foreignId('journal_id')->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['side', 'party_id', 'document_no']);
            $table->index(['side', 'party_id', 'due_date']);
        });
        DB::statement("ALTER TABLE open_items ADD CONSTRAINT open_items_side_check CHECK (side IN ('receivable','payable'))");
        DB::statement('ALTER TABLE open_items ADD CONSTRAINT open_items_amounts_check CHECK (amount > 0 AND open_amount >= 0 AND open_amount <= amount AND due_date >= document_date)');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX open_items_one_per_source ON open_items (side, source_type, source_id) WHERE source_type IS NOT NULL');
        }

        // Money received from a customer (receipt) or paid to a supplier (payment).
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('side', 10);                           // receivable (receipt) | payable (payment)
            $table->string('document_no', 40)->unique();
            $table->unsignedBigInteger('party_id');
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->date('settled_on')->index();
            $table->decimal('amount', 18, 4);
            $table->string('method', 15)->default('bank_transfer'); // cash|cheque|bank_transfer|card|other
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('status', 10)->default('posted');      // posted|reversed
            $table->foreignId('journal_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['side', 'party_id']);
        });
        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_check CHECK (side IN ('receivable','payable') AND amount > 0 AND status IN ('posted','reversed') AND method IN ('cash','cheque','bank_transfer','card','other'))");

        // Which invoices/bills a receipt/payment settled, and how much of each. Rows never change.
        Schema::create('settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->restrictOnDelete();
            $table->foreignId('open_item_id')->constrained('open_items')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamp('created_at')->useCurrent();
            $table->index('open_item_id');
            $table->index('settlement_id');
        });
        DB::statement('ALTER TABLE settlement_allocations ADD CONSTRAINT settlement_allocations_check CHECK (amount > 0)');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER settlement_allocations_append_only BEFORE UPDATE OR DELETE ON settlement_allocations FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION settlement_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'receipts and payments cannot be deleted';
                    END IF;
                    IF (to_jsonb(NEW) - 'status' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'updated_at') THEN
                        RAISE EXCEPTION 'posted receipts and payments cannot be changed; reverse them instead';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('CREATE TRIGGER settlements_guard BEFORE UPDATE OR DELETE ON settlements FOR EACH ROW EXECUTE FUNCTION settlement_guard()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_allocations');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('open_items');
        Schema::dropIfExists('bank_accounts');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS settlement_guard()');
        }
    }
};
