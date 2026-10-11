<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stock promised to something (a sales order, a production order) but not yet moved. Held per item and warehouse.
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('qty', 18, 6);           // base units
            $table->decimal('consumed_qty', 18, 6)->default(0);
            $table->string('status', 10)->default('active'); // active|released|consumed
            $table->string('source_type', 60);
            $table->string('source_id', 60);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_check CHECK (qty > 0 AND consumed_qty >= 0 AND consumed_qty <= qty AND status IN ('active','released','consumed'))");

        // Minimum level for an item (in one warehouse, or company-wide when warehouse_id is null).
        Schema::create('reorder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->decimal('min_qty', 18, 6);       // base units; "below reorder" means available < min_qty
            $table->decimal('reorder_qty', 18, 6)->nullable(); // suggested order quantity
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE reorder_rules ADD CONSTRAINT reorder_rules_check CHECK (min_qty >= 0 AND (reorder_qty IS NULL OR reorder_qty > 0))');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX reorder_rules_item_warehouse ON reorder_rules (item_id, warehouse_id) WHERE warehouse_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX reorder_rules_item_company ON reorder_rules (item_id) WHERE warehouse_id IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_rules');
        Schema::dropIfExists('stock_reservations');
    }
};
