<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether the unit prices on a document include VAT. Documents made before this were entered without VAT.
        foreach (['purchase_orders', 'supplier_bills'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('prices_include_vat')->default(false);
            });
        }
        // Input VAT per order line, so a printed order can show line totals including VAT.
        Schema::table('purchase_order_lines', function (Blueprint $t) {
            $t->decimal('vat_amount', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        foreach (['purchase_orders', 'supplier_bills'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('prices_include_vat'));
        }
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropColumn('vat_amount'));
    }
};
