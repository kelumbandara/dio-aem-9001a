<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('vat_number', 40)->nullable();
            $table->string('installation_id', 100)->nullable()->unique();
            $table->string('api_token_hash', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained();
            $table->string('plan', 60);
            $table->decimal('monthly_price', 14, 2);
            $table->date('started_on');
            $table->date('trial_ends_on');
            $table->date('billing_anchor');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->string('financial_year', 7)->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('subscription_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('net', 14, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('vat', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('status', 10)->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->string('public_token', 40)->unique();
            $table->jsonb('billed_to');
            $table->jsonb('issuer');
            $table->jsonb('reminders_sent')->default('[]');
            $table->timestamps();
            $table->unique(['subscription_id', 'period_start']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->string('method', 20);
            $table->decimal('amount', 14, 2);
            $table->date('received_on');
            $table->string('reference')->nullable();
            $table->string('payhere_payment_id', 60)->nullable()->unique();
            $table->jsonb('details')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users');
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('issued_licences', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 60)->unique();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->string('installation_id', 100);
            $table->string('kind', 12)->default('renewal');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->text('token');
            $table->timestamp('issued_at');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('action', 60)->index();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION billing_refuse_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% on % is not allowed: records are kept permanently', TG_OP, TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER activity_log_immutable BEFORE UPDATE OR DELETE ON activity_log FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();
CREATE TRIGGER issued_licences_immutable BEFORE DELETE ON issued_licences FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();
CREATE TRIGGER invoices_no_delete BEFORE DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();
CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();

CREATE OR REPLACE FUNCTION billing_invoice_guard() RETURNS trigger AS $$
BEGIN
    IF (NEW.number, NEW.customer_id, NEW.subscription_id, NEW.period_start, NEW.period_end, NEW.issue_date, NEW.due_date, NEW.net, NEW.vat_rate, NEW.vat, NEW.total, NEW.billed_to, NEW.issuer, NEW.public_token)
       IS DISTINCT FROM
       (OLD.number, OLD.customer_id, OLD.subscription_id, OLD.period_start, OLD.period_end, OLD.issue_date, OLD.due_date, OLD.net, OLD.vat_rate, OLD.vat, OLD.total, OLD.billed_to, OLD.issuer, OLD.public_token) THEN
        RAISE EXCEPTION 'An issued invoice cannot be changed';
    END IF;
    IF OLD.status = 'void' AND NEW.status <> 'void' THEN
        RAISE EXCEPTION 'A void invoice cannot be reopened';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER invoices_guard BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION billing_invoice_guard();

CREATE OR REPLACE FUNCTION billing_payment_guard() RETURNS trigger AS $$
BEGIN
    IF (NEW.invoice_id, NEW.method, NEW.amount, NEW.received_on, NEW.reference, NEW.payhere_payment_id, NEW.entered_by)
       IS DISTINCT FROM
       (OLD.invoice_id, OLD.method, OLD.amount, OLD.received_on, OLD.reference, OLD.payhere_payment_id, OLD.entered_by) THEN
        RAISE EXCEPTION 'A payment cannot be changed; reverse it instead';
    END IF;
    IF OLD.reversed_at IS NOT NULL THEN
        RAISE EXCEPTION 'A reversed payment cannot be changed';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER payments_guard BEFORE UPDATE ON payments FOR EACH ROW EXECUTE FUNCTION billing_payment_guard();

CREATE OR REPLACE FUNCTION billing_licence_guard() RETURNS trigger AS $$
BEGIN
    IF (NEW.serial, NEW.customer_id, NEW.installation_id, NEW.valid_from, NEW.valid_until, NEW.token, NEW.issued_at)
       IS DISTINCT FROM
       (OLD.serial, OLD.customer_id, OLD.installation_id, OLD.valid_from, OLD.valid_until, OLD.token, OLD.issued_at) THEN
        RAISE EXCEPTION 'An issued licence cannot be changed';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER issued_licences_guard BEFORE UPDATE ON issued_licences FOR EACH ROW EXECUTE FUNCTION billing_licence_guard();
SQL);
        }
    }

    public function down(): void
    {
        foreach (['activity_log', 'issued_licences', 'payments', 'invoices', 'invoice_counters', 'subscriptions', 'customers', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS billing_refuse_change(), billing_invoice_guard(), billing_payment_guard(), billing_licence_guard() CASCADE');
        }
    }
};
