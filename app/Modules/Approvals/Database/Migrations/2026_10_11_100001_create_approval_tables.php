<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which actions need approval, under which conditions, and by whom (docs/architecture/approvals.md).
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('action_key', 100)->index();
            $table->json('risk_levels')->nullable(); // ["R2","R3"]; null = any
            $table->decimal('min_amount', 18, 4)->nullable(); // applies when amount >= min
            $table->decimal('max_amount', 18, 4)->nullable(); // and amount <= max
            $table->json('branch_ids')->nullable(); // null = every branch
            $table->unsignedSmallInteger('priority')->default(100); // lower is evaluated first
            $table->unsignedSmallInteger('expiry_hours')->default(72);
            $table->unsignedSmallInteger('reminder_after_hours')->default(24);
            $table->boolean('allow_self_approval')->default(false);
            $table->boolean('distinct_approvers')->default(true); // one person cannot satisfy two steps
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        // Steps with the same step_order run in parallel; the next order starts when all of them approved.
        Schema::create('approval_policy_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('approval_policies')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('approver_type', 10); // role|user
            $table->string('approver_role', 100)->nullable();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('label', 100)->nullable();
            $table->timestamps();
            $table->index(['policy_id', 'step_order']);
        });
        DB::statement("ALTER TABLE approval_policy_steps ADD CONSTRAINT approval_policy_steps_approver_check CHECK (
            (approver_type = 'role' AND approver_role IS NOT NULL AND approver_user_id IS NULL) OR
            (approver_type = 'user' AND approver_user_id IS NOT NULL AND approver_role IS NULL))");

        // Who may approve how much. Evaluated for an action only when it has at least one row.
        Schema::create('approval_authority_matrix', function (Blueprint $table) {
            $table->id();
            $table->string('role_name', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action_key', 100)->default('*'); // '*' = every action
            $table->decimal('max_amount', 18, 4)->nullable(); // null = unlimited
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index('action_key');
        });
        DB::statement('ALTER TABLE approval_authority_matrix ADD CONSTRAINT approval_authority_holder_check CHECK (
            (role_name IS NOT NULL AND user_id IS NULL) OR (role_name IS NULL AND user_id IS NOT NULL))');
        DB::statement('ALTER TABLE approval_authority_matrix ADD CONSTRAINT approval_authority_amount_check CHECK (max_amount IS NULL OR max_amount >= 0)');

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 50)->unique();
            $table->string('action_key', 100)->index();
            $table->foreignId('policy_id')->constrained('approval_policies')->restrictOnDelete();
            $table->json('policy_snapshot'); // conditions + steps as they were when requested
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('summary');
            $table->json('payload'); // immutable snapshot of what will be executed
            $table->char('payload_hash', 64);
            $table->decimal('amount', 18, 4)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('channel', 10)->default('web'); // web|api|ai|job
            $table->string('ai_run_id', 64)->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('current_step_order')->default(1);
            $table->timestamp('expires_at')->index();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['requested_by', 'idempotency_key']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['requested_by', 'status']);
        });

        Schema::create('approval_request_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('approver_type', 10);
            $table->string('approver_role', 100)->nullable();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('status', 10)->default('waiting'); // waiting|pending|approved|rejected|skipped
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['request_id', 'step_order']);
            $table->index(['status', 'approver_role']);
        });

        // Every decision with the hash that was approved. Append-only (trigger below on PostgreSQL).
        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('step_id')->constrained('approval_request_steps')->restrictOnDelete();
            $table->string('decision', 10); // approve|reject
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('on_behalf_of')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->char('payload_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('channel', 10)->default('web');
            $table->timestamp('decided_at')->useCurrent();
            $table->index('request_id');
        });

        // Time-boxed hand-over of approval rights. Authority limits stay those of the delegator.
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->restrictOnDelete();
            $table->string('action_key', 100)->nullable(); // null = every action
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['delegate_id', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_check CHECK (ends_at > starts_at AND delegator_id <> delegate_id)');

        // One row per execution attempt; at most one may ever succeed.
        Schema::create('approval_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 10); // succeeded|failed
            $table->json('result')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at')->useCurrent();
            $table->unique(['request_id', 'attempt']);
        });
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check CHECK (
            status IN ('pending','approved','rejected','expired','cancelled','executed','execution_failed'))");

        // PostgreSQL-only guarantees (the engine also enforces the same rules under a row lock).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX approval_executions_one_success ON approval_executions (request_id) WHERE status = 'succeeded'");

            foreach (['approval_decisions', 'approval_executions'] as $table) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$table}_append_only
                    BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()
                SQL);
            }
        }
    }

    public function down(): void
    {
        foreach (['approval_executions', 'approval_delegations', 'approval_decisions', 'approval_request_steps',
            'approval_requests', 'approval_authority_matrix', 'approval_policy_steps', 'approval_policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
