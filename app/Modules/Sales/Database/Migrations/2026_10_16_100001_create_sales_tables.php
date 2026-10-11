<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ quotations
        Schema::create('sales_quotations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('quote_date');
            $table->date('valid_until');
            $table->string('status', 20)->default('draft');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->decimal('net_total', 18, 4)->default(0);
            $table->decimal('vat_total', 18, 4)->default(0);
            $table->decimal('levy_total', 18, 4)->default(0);
            $table->decimal('gross_total', 18, 4)->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });
        DB::statement("ALTER TABLE sales_quotations ADD CONSTRAINT sales_quotations_status_check CHECK (status IN ('draft','sent','accepted','rejected','converted','cancelled'))");

        // ------------------------------------------------------------------ orders
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('sales_quotations')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('order_date');
            $table->date('required_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('customer_ref', 100)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->text('notes')->nullable();
            $table->decimal('net_total', 18, 4)->default(0);
            $table->decimal('vat_total', 18, 4)->default(0);
            $table->decimal('levy_total', 18, 4)->default(0);
            $table->decimal('gross_total', 18, 4)->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('credit_override_reason', 255)->nullable();
            $table->foreignId('credit_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_status_check CHECK (status IN ('draft','confirmed','completed','cancelled'))");

        foreach (['sales_quotation_lines' => 'sales_quotations', 'sales_order_lines' => 'sales_orders'] as $name => $parent) {
            Schema::create($name, function (Blueprint $table) use ($name, $parent) {
                $table->id();
                $table->foreignId('document_id')->constrained($parent)->cascadeOnDelete();
                $table->unsignedSmallInteger('line_no');
                $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
                $table->string('description', 255)->nullable();
                $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
                $table->decimal('qty', 18, 6);
                $table->decimal('unit_price', 18, 4);   // net of tax, per `uom_id` unit
                $table->decimal('discount_pct', 7, 4)->default(0);
                $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
                $table->decimal('net_amount', 18, 4);
                if ($name === 'sales_order_lines') {
                    $table->decimal('qty_delivered', 18, 6)->default(0);
                    $table->decimal('qty_invoiced', 18, 6)->default(0);
                    $table->unsignedBigInteger('reservation_id')->nullable();
                }
                $table->unique(['document_id', 'line_no']);
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_amounts_check CHECK (qty > 0 AND unit_price >= 0 AND discount_pct >= 0 AND discount_pct <= 100 AND net_amount >= 0)");
        }
        DB::statement('ALTER TABLE sales_order_lines ADD CONSTRAINT sales_order_lines_progress_check CHECK (qty_delivered >= 0 AND qty_invoiced >= 0 AND qty_delivered <= qty AND qty_invoiced <= qty)');

        // ------------------------------------------------------------------ deliveries
        Schema::create('sales_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();
            $table->foreignId('order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('delivery_date');
            $table->string('status', 20)->default('posted');
            $table->text('notes')->nullable();
            $table->foreignId('stock_document_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('reversal_document_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('order_id');
        });
        DB::statement("ALTER TABLE sales_deliveries ADD CONSTRAINT sales_deliveries_status_check CHECK (status IN ('posted','reversed'))");

        Schema::create('sales_delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('sales_deliveries')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->index('delivery_id');
        });
        DB::statement('ALTER TABLE sales_delivery_lines ADD CONSTRAINT sales_delivery_lines_qty_check CHECK (qty > 0)');

        // ------------------------------------------------------------------ invoices (immutable once posted)
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();
            $table->foreignId('order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('customer_ref', 100)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('net_total', 18, 4);
            $table->decimal('vat_total', 18, 4);
            $table->decimal('levy_total', 18, 4);
            $table->decimal('gross_total', 18, 4);
            $table->foreignId('journal_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'invoice_date']);
            $table->index('order_id');
        });

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_pct', 7, 4)->default(0);
            $table->decimal('net_amount', 18, 4);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('vat_rate', 9, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->boolean('levy_liable')->default(true);
            $table->unique(['invoice_id', 'line_no']);
        });
        DB::statement('ALTER TABLE sales_invoice_lines ADD CONSTRAINT sales_invoice_lines_check CHECK (qty > 0 AND net_amount >= 0 AND vat_amount >= 0)');

        // One row per tax code on the document: what the invoice print shows and what is posted to the tax accounts.
        Schema::create('sales_invoice_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('rate', 9, 4);
            $table->decimal('base_amount', 18, 4);
            $table->decimal('amount', 18, 4);
            $table->unique(['invoice_id', 'tax_code_id']);
        });

        // ------------------------------------------------------------------ credit notes (immutable)
        Schema::create('sales_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();
            $table->foreignId('invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('note_date');
            $table->string('reason', 255);
            $table->boolean('restock')->default(false);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->decimal('net_total', 18, 4);
            $table->decimal('vat_total', 18, 4);
            $table->decimal('levy_total', 18, 4);
            $table->decimal('gross_total', 18, 4);
            $table->foreignId('journal_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('stock_document_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('invoice_id');
        });

        Schema::create('sales_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('sales_credit_notes')->restrictOnDelete();
            $table->foreignId('invoice_line_id')->constrained('sales_invoice_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->decimal('net_amount', 18, 4);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->index('credit_note_id');
        });
        DB::statement('ALTER TABLE sales_credit_note_lines ADD CONSTRAINT sales_credit_note_lines_check CHECK (qty > 0 AND net_amount >= 0 AND vat_amount >= 0)');

        Schema::create('sales_credit_note_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('sales_credit_notes')->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('rate', 9, 4);
            $table->decimal('base_amount', 18, 4);
            $table->decimal('amount', 18, 4);
            $table->unique(['credit_note_id', 'tax_code_id']);
        });

        // Posted documents never change and are never deleted: a mistake is corrected by a credit note or a reversal.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sales_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Posted sales documents are immutable (% on %)', TG_OP, TG_TABLE_NAME USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        foreach (['sales_invoices', 'sales_invoice_lines', 'sales_invoice_taxes', 'sales_credit_notes', 'sales_credit_note_lines', 'sales_credit_note_taxes', 'sales_delivery_lines'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION sales_block_mutation()");
        }
        DB::statement('CREATE TRIGGER sales_deliveries_no_delete BEFORE DELETE ON sales_deliveries FOR EACH ROW EXECUTE FUNCTION sales_block_mutation()');
    }

    public function down(): void
    {
        foreach (['sales_credit_note_taxes', 'sales_credit_note_lines', 'sales_credit_notes', 'sales_invoice_taxes', 'sales_invoice_lines', 'sales_invoices', 'sales_delivery_lines', 'sales_deliveries', 'sales_order_lines', 'sales_orders', 'sales_quotation_lines', 'sales_quotations'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS sales_block_mutation()');
    }
};
