<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ purchase orders
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('supplier_ref', 100)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->text('notes')->nullable();
            $table->decimal('net_total', 18, 4)->default(0);
            $table->decimal('vat_total', 18, 4)->default(0);
            $table->decimal('gross_total', 18, 4)->default(0);
            $table->string('approval_request_id', 40)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft','pending_approval','approved','completed','cancelled'))");

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->decimal('unit_price', 18, 4);   // net of VAT, per `uom_id` unit, before discount
            $table->decimal('discount_pct', 7, 4)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('net_amount', 18, 4);
            $table->decimal('qty_received', 18, 6)->default(0);
            $table->decimal('qty_returned', 18, 6)->default(0);
            $table->decimal('qty_billed', 18, 6)->default(0);
            $table->unique(['document_id', 'line_no']);
        });
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_check CHECK (qty > 0 AND unit_price >= 0 AND discount_pct BETWEEN 0 AND 100 AND net_amount >= 0 AND qty_received >= 0 AND qty_returned >= 0 AND qty_billed >= 0 AND qty_returned <= qty_received AND qty_received - qty_returned <= qty AND qty_billed <= qty)');

        // ------------------------------------------------------------------ goods receipts
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();
            $table->foreignId('order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('receipt_date');
            $table->string('status', 20)->default('posted');
            $table->string('supplier_dn_ref', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('stock_document_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('reversal_document_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('order_id');
        });
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_status_check CHECK (status IN ('posted','reversed'))");

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->decimal('unit_cost', 18, 6);   // per `uom_id` unit, LKR, net of VAT
            $table->decimal('value', 18, 4);       // what stock was valued at: the goods-received-not-invoiced amount
            $table->string('lot_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->index('receipt_id');
        });
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_check CHECK (qty > 0 AND unit_cost >= 0 AND value >= 0)');

        // ------------------------------------------------------------------ supplier bills (immutable once posted)
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();            // our own number
            $table->string('supplier_invoice_no', 60);              // the number printed on the supplier's invoice (also the payable's document number)
            $table->foreignId('order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('bill_date');
            $table->date('due_date');
            $table->text('notes')->nullable();
            $table->decimal('net_total', 18, 4);
            $table->decimal('vat_total', 18, 4);
            $table->decimal('gross_total', 18, 4);
            $table->decimal('variance_total', 18, 4)->default(0);   // + dearer than goods received, - cheaper
            $table->string('override_reason', 255)->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['supplier_id', 'supplier_invoice_no']);
            $table->index('order_id');
        });

        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('supplier_bills')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->decimal('unit_price', 18, 6);          // the supplier's net unit price
            $table->decimal('net_amount', 18, 4);          // = grni_amount + variance_amount (stock lines) or the expense (non-stock)
            $table->decimal('grni_amount', 18, 4)->default(0);
            $table->decimal('variance_amount', 18, 4)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('vat_rate', 9, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->boolean('stocked')->default(true);
            $table->unique(['bill_id', 'line_no']);
        });
        DB::statement('ALTER TABLE supplier_bill_lines ADD CONSTRAINT supplier_bill_lines_check CHECK (qty > 0 AND net_amount >= 0 AND grni_amount >= 0 AND vat_amount >= 0)');

        // ------------------------------------------------------------------ purchase returns (goods back to the supplier, optionally with a debit note)
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();            // also the debit note number when a bill is credited
            $table->foreignId('order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('supplier_bills')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('return_date');
            $table->string('reason', 255);
            $table->decimal('net_total', 18, 4)->default(0);       // debit note amounts (zero without a bill)
            $table->decimal('vat_total', 18, 4)->default(0);
            $table->decimal('gross_total', 18, 4)->default(0);
            $table->foreignId('stock_document_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('order_id');
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('bill_line_id')->nullable()->constrained('supplier_bill_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->boolean('goods_returned')->default(true);       // false for a debit note on a non-stock line
            $table->decimal('grni_value', 18, 4)->default(0);       // goods-received-not-invoiced taken back by the goods leaving, at the order's cost
            $table->decimal('net_amount', 18, 4)->default(0);       // debit note parts
            $table->decimal('grni_amount', 18, 4)->default(0);
            $table->decimal('variance_amount', 18, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->index('return_id');
        });
        DB::statement('ALTER TABLE purchase_return_lines ADD CONSTRAINT purchase_return_lines_check CHECK (qty > 0 AND net_amount >= 0 AND grni_amount >= 0 AND vat_amount >= 0 AND grni_value >= 0)');

        // Posted documents never change and are never deleted: a mistake is corrected by a return, a debit note or a reversal.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION procurement_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Posted purchasing documents are immutable (% on %)', TG_OP, TG_TABLE_NAME USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        foreach (['supplier_bills', 'supplier_bill_lines', 'purchase_returns', 'purchase_return_lines', 'goods_receipt_lines'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION procurement_block_mutation()");
        }
        DB::statement('CREATE TRIGGER goods_receipts_no_delete BEFORE DELETE ON goods_receipts FOR EACH ROW EXECUTE FUNCTION procurement_block_mutation()');
    }

    public function down(): void
    {
        foreach (['purchase_return_lines', 'purchase_returns', 'supplier_bill_lines', 'supplier_bills', 'goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS procurement_block_mutation()');
    }
};
