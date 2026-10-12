<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 120);
            $table->string('channel', 20)->default('web');
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        // What was said. Never edited or deleted: the answer a person acted on stays readable.
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->restrictOnDelete();
            $table->string('role', 10);
            $table->text('content');
            $table->jsonb('refs')->nullable();                      // documents the answer rests on: [{label, url}]
            $table->unsignedBigInteger('execution_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
        });
        DB::statement("ALTER TABLE ai_messages ADD CONSTRAINT ai_messages_role_check CHECK (role IN ('user','assistant'))");

        // The audit trail of every model run: who asked, which tools ran with what input, tokens and cost. The question text itself is
        // in ai_messages; here only its hash, so the trail can prove what was sent without repeating personal data.
        Schema::create('ai_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('agent', 40);
            $table->string('model', 80);
            $table->string('prompt_hash', 64);
            $table->string('status', 20);
            $table->unsignedSmallInteger('steps')->default(0);
            $table->jsonb('tool_calls');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->string('error_code', 60)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_status_check CHECK (status IN ('ok','error','budget','rate_limited','max_steps'))");
        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_check CHECK (cost_usd >= 0 AND steps >= 0)');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ai_refuse_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% on % is not allowed: the AI conversation and its audit trail are kept as they were', TG_OP, TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER ai_messages_immutable BEFORE UPDATE OR DELETE ON ai_messages FOR EACH ROW EXECUTE FUNCTION ai_refuse_change();
CREATE TRIGGER ai_executions_immutable BEFORE UPDATE OR DELETE ON ai_executions FOR EACH ROW EXECUTE FUNCTION ai_refuse_change();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_executions');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ai_refuse_change()');
        }
    }
};
