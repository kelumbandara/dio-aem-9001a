<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 100)->index();
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('channel', 20)->default('system'); // web|api|ai|job|console|system
            $table->string('correlation_id', 64)->nullable()->index();
            $table->index(['auditable_type', 'auditable_id']);
        });

        // Append-only: enforced in the database, not only in the model.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_row_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '% on % is not allowed: table is append-only', TG_OP, TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
