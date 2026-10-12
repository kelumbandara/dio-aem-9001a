<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every licence ever installed, kept as issued: the newest issue is the one in force, the rest are history.
        Schema::create('licences', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 80)->unique();
            $table->text('token');
            $table->string('installation_id', 60);
            $table->string('company', 150);
            $table->string('plan', 60);
            $table->date('valid_from');
            $table->date('valid_until');
            $table->timestamp('issued_at');
            $table->unsignedInteger('max_users')->nullable();
            $table->json('modules')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('issued_at');
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION licences_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Installed licences are history and cannot be changed (% on %)', TG_OP, TG_TABLE_NAME USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER licences_immutable BEFORE UPDATE OR DELETE ON licences FOR EACH ROW EXECUTE FUNCTION licences_block_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('licences');
        DB::statement('DROP FUNCTION IF EXISTS licences_block_mutation()');
    }
};
