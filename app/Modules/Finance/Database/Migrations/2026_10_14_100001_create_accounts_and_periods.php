<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The chart of accounts: groups (not postable) and accounts (postable) in one tree. Accounts are never deleted.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 12)->unique();
            $table->string('name', 150);
            $table->string('class', 10);                       // asset|liability|equity|income|expense
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_group')->default(false);
            $table->string('subtype', 20)->nullable();         // bank|cash|receivable|payable|inventory|wip|retained_earnings|year_result
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index('class');
            $table->index('parent_id');
        });
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_class_check CHECK (class IN ('asset','liability','equity','income','expense'))");

        // Which account each automatic posting uses ("posting rules"): inventory, cost of sales, receivables control…
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->unique();              // 2026/27
            $table->date('starts_on')->unique();
            $table->date('ends_on')->unique();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_dates_check CHECK (ends_on > starts_on)');

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->string('name', 20);                        // Apr 2026
            $table->date('starts_on')->unique();
            $table->date('ends_on')->unique();
            $table->string('status', 8)->default('open');      // open|closed|locked
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });
        DB::statement("ALTER TABLE fiscal_periods ADD CONSTRAINT fiscal_periods_status_check CHECK (status IN ('open','closed','locked'))");
        DB::statement('ALTER TABLE fiscal_periods ADD CONSTRAINT fiscal_periods_dates_check CHECK (ends_on >= starts_on)');
        if (DB::getDriverName() === 'pgsql') {
            // A locked period never changes again.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION fiscal_period_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'fiscal periods cannot be deleted';
                    END IF;
                    IF OLD.status = 'locked' THEN
                        RAISE EXCEPTION 'a locked fiscal period cannot be changed';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('CREATE TRIGGER fiscal_periods_guard BEFORE UPDATE OR DELETE ON fiscal_periods FOR EACH ROW EXECUTE FUNCTION fiscal_period_guard()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('account_mappings');
        Schema::dropIfExists('accounts');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS fiscal_period_guard()');
        }
    }
};
