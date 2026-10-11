<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stock ledger is the truth. Rows are never changed or deleted; a correction is a new, opposite row.
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->date('posting_date');
            $table->foreignId('document_id')->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('stock_document_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete();
            $table->decimal('qty', 18, 6);        // base units, + in / - out
            $table->decimal('unit_cost', 18, 6);  // value / qty, for display; the value below is the authority
            $table->decimal('value', 18, 4);      // LKR, same sign as qty
            $table->foreignId('reverses_id')->nullable()->constrained('stock_ledger')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['item_id', 'posting_date', 'id']);
            $table->index(['location_id', 'item_id']);
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_signs_check CHECK (qty <> 0 AND (value = 0 OR sign(value) = sign(qty)))');

        // Current quantity per item / location / lot, kept in step with the ledger inside the same transaction.
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete();
            $table->decimal('on_hand', 18, 6)->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });
        // Stock can never be negative: the database refuses it even if application code is wrong.
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_non_negative CHECK (on_hand >= 0)');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX stock_balances_with_lot ON stock_balances (item_id, location_id, lot_id) WHERE lot_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX stock_balances_without_lot ON stock_balances (item_id, location_id) WHERE lot_id IS NULL');
        }
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->index(['location_id', 'item_id']);
        });

        // Weighted-average cost per item across the company: quantity and value always equal the sums of the ledger.
        Schema::create('item_costs', function (Blueprint $table) {
            $table->foreignId('item_id')->primary()->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 18, 6)->default(0);
            $table->decimal('value', 18, 4)->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });
        DB::statement('ALTER TABLE item_costs ADD CONSTRAINT item_costs_non_negative CHECK (qty >= 0 AND value >= 0 AND (qty > 0 OR value = 0))');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER stock_ledger_append_only BEFORE UPDATE OR DELETE ON stock_ledger FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_costs');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_ledger');
    }
};
