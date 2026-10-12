<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A supplier debit note settles a bill without money moving, like a customer credit note does for an invoice.
        DB::statement('ALTER TABLE settlements DROP CONSTRAINT settlements_check');
        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_check CHECK (side IN ('receivable','payable') AND amount > 0 AND status IN ('posted','reversed') AND method IN ('cash','cheque','bank_transfer','card','other','credit_note','debit_note') AND ((method IN ('credit_note','debit_note')) = (bank_account_id IS NULL)) AND (method <> 'credit_note' OR side = 'receivable') AND (method <> 'debit_note' OR side = 'payable'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE settlements DROP CONSTRAINT settlements_check');
        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_check CHECK (side IN ('receivable','payable') AND amount > 0 AND status IN ('posted','reversed') AND method IN ('cash','cheque','bank_transfer','card','other','credit_note') AND ((method = 'credit_note') = (bank_account_id IS NULL)))");
    }
};
