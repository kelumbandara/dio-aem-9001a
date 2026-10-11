<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A journal entry is posted the moment it exists (no drafts) and balanced by construction and by the database.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('journal_no', 40)->unique();
            $table->date('entry_date')->index();
            $table->foreignId('period_id')->constrained('fiscal_periods')->restrictOnDelete();
            $table->string('status', 10)->default('posted');      // posted|reversed
            $table->string('kind', 10)->default('manual');        // manual|system|reversal|opening
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            // The business document that caused it (a stock document, an invoice…); one journal per source and event.
            $table->string('source_type', 60)->nullable();
            $table->string('source_id', 60)->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->decimal('total', 18, 4);                      // sum of debits (= sum of credits)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_check CHECK (status IN ('posted','reversed'))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_kind_check CHECK (kind IN ('manual','system','reversal','opening'))");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX journal_entries_one_per_source ON journal_entries (source_type, source_id) WHERE source_type IS NOT NULL AND kind <> \'reversal\'');
        }

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->string('description', 255)->nullable();
            $table->string('party_type', 10)->nullable();          // customer|supplier
            $table->unsignedBigInteger('party_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['entry_id', 'line_no']);
            $table->index(['account_id', 'entry_id']);
            $table->index(['party_type', 'party_id']);
        });
        // Exactly one side per line, never negative.
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side_check CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)))');
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_party_check CHECK ((party_type IS NULL AND party_id IS NULL) OR (party_type IN ('customer','supplier') AND party_id IS NOT NULL))");

        if (DB::getDriverName() === 'pgsql') {
            // Lines never change; an entry only gets marked reversed.
            DB::unprepared('CREATE TRIGGER journal_lines_append_only BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION journal_entry_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'journal entries cannot be deleted';
                    END IF;
                    IF (to_jsonb(NEW) - 'status' - 'reversed_by_id' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reversed_by_id' - 'updated_at') THEN
                        RAISE EXCEPTION 'posted journal entries cannot be changed; reverse them instead';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('CREATE TRIGGER journal_entries_guard BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION journal_entry_guard()');

            // The books must balance: checked again by the database when the transaction commits.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION journal_balance_check() RETURNS trigger AS $$
                DECLARE d numeric; c numeric; t numeric;
                BEGIN
                    SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0) INTO d, c FROM journal_lines WHERE entry_id = NEW.entry_id;
                    SELECT total INTO t FROM journal_entries WHERE id = NEW.entry_id;
                    IF d <> c OR d <> t THEN
                        RAISE EXCEPTION 'journal entry % is not balanced (debits %, credits %, total %)', NEW.entry_id, d, c, t;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('CREATE CONSTRAINT TRIGGER journal_lines_balanced AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_balance_check()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS journal_entry_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS journal_balance_check()');
        }
    }
};
