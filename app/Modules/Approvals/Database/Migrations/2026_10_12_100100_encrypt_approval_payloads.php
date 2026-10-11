<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval payloads can carry sensitive data (e.g. a supplier's new bank account number), so they are
 * stored encrypted. Existing rows are re-encrypted in place. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE approval_requests ALTER COLUMN payload TYPE text USING payload::text');
        } else {
            Schema::table('approval_requests', fn ($table) => $table->text('payload')->change());
        }

        DB::table('approval_requests')->orderBy('id')->each(function ($row) {
            DB::table('approval_requests')->where('id', $row->id)->update(['payload' => Crypt::encryptString($row->payload)]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Encrypting approval payloads is forward-only.');
    }
};
