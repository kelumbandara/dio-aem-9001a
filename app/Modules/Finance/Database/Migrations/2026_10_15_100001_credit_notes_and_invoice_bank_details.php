<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A credit note settles an invoice without money moving, so it has no bank account and its own method.
        DB::statement('ALTER TABLE settlements ALTER COLUMN bank_account_id DROP NOT NULL');
        DB::statement('ALTER TABLE settlements DROP CONSTRAINT settlements_check');
        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_check CHECK (side IN ('receivable','payable') AND amount > 0 AND status IN ('posted','reversed') AND method IN ('cash','cheque','bank_transfer','card','other','credit_note') AND ((method = 'credit_note') = (bank_account_id IS NULL)))");

        // Which bank accounts are printed on invoices so customers know where to pay.
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->boolean('show_on_invoices')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('show_on_invoices');
        });
    }
};
