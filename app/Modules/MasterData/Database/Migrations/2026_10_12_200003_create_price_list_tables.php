<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('type', 10); // sales|purchase
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->boolean('tax_inclusive')->default(false);
            $table->boolean('is_default')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE price_lists ADD CONSTRAINT price_lists_type_check CHECK (type IN ('sales','purchase'))");
        DB::statement('ALTER TABLE price_lists ADD CONSTRAINT price_lists_dates_check CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)');

        // One price per item, unit and minimum quantity (quantity breaks).
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('min_qty', 18, 6)->default(0);
            $table->decimal('unit_price', 18, 6);
            $table->timestamps();
            $table->unique(['price_list_id', 'item_id', 'uom_id', 'min_qty']);
            $table->index('item_id');
        });
        DB::statement('ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_check CHECK (min_qty >= 0 AND unit_price >= 0)');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX price_lists_one_default_per_type ON price_lists (type) WHERE is_default');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
