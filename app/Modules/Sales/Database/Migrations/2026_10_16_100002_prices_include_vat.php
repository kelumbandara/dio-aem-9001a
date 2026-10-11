<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether the unit prices on a document include VAT. Existing documents were entered without VAT.
        foreach (['sales_quotations', 'sales_orders', 'sales_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('prices_include_vat')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_quotations', 'sales_orders', 'sales_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('prices_include_vat');
            });
        }
    }
};
