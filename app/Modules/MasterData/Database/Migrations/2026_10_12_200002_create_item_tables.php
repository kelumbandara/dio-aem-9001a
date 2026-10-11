<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 40)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained('item_categories')->restrictOnDelete();
            $table->string('item_type', 12)->default('stock'); // stock|service|non_stock
            $table->foreignId('base_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->string('tracking', 8)->default('none'); // none|lot
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->string('barcode', 64)->nullable()->unique();
            $table->foreignId('default_tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index('category_id');
            $table->index('item_type');
            $table->index('name');
        });
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_type_check CHECK (item_type IN ('stock','service','non_stock'))");
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_tracking_check CHECK (tracking IN ('none','lot') AND (tracking = 'none' OR item_type = 'stock'))");
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_shelf_life_check CHECK (shelf_life_days IS NULL OR (shelf_life_days > 0 AND tracking = 'lot'))");

        // Other units an item is bought/sold in. factor = how many BASE units one of this unit contains.
        Schema::create('item_uoms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('factor', 18, 6);
            $table->boolean('for_purchase')->default(true);
            $table->boolean('for_sale')->default(true);
            $table->timestamps();
            $table->unique(['item_id', 'uom_id']);
        });
        DB::statement('ALTER TABLE item_uoms ADD CONSTRAINT item_uoms_factor_check CHECK (factor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('item_uoms');
        Schema::dropIfExists('items');
    }
};
