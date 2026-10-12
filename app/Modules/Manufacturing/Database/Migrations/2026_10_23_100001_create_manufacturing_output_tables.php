<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downtime_reasons', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('category', 12);                        // planned | breakdown | changeover | material | quality | other
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE downtime_reasons ADD CONSTRAINT downtime_reasons_check CHECK (category IN ('planned','breakdown','changeover','material','quality','other'))");

        Schema::table('production_orders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('fg_value', 18, 4)->default(0);          // LKR of material cost taken into finished-goods stock so far
            $table->decimal('variance_value', 18, 4)->nullable();    // LKR of material cost not absorbed by good output, fixed at completion
        });

        // Output reported by operators, always for a shift. A report is never edited; a mistake is voided and entered again.
        Schema::create('production_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('production_order_operations')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->restrictOnDelete();
            $table->string('kind', 12)->default('output');          // output | rework_done
            $table->date('report_date');                             // the day the shift started
            $table->decimal('produced_qty', 18, 6);                  // output: all units made (good, reject and rework together); rework_done: units back from rework
            $table->decimal('reject_qty', 18, 6)->default(0);
            $table->decimal('rework_qty', 18, 6)->default(0);
            $table->string('notes', 500)->nullable();
            $table->string('source', 10)->default('manual');         // manual | device
            $table->string('external_event_id', 100)->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'report_date']);
            $table->index(['report_date', 'shift_id']);
        });
        DB::statement("ALTER TABLE production_reports ADD CONSTRAINT production_reports_check CHECK (kind IN ('output','rework_done') AND source IN ('manual','device') AND produced_qty > 0 AND reject_qty >= 0 AND rework_qty >= 0 AND reject_qty + rework_qty <= produced_qty AND (kind = 'output' OR (reject_qty = 0 AND rework_qty = 0)))");
        DB::statement('CREATE UNIQUE INDEX production_reports_external_unique ON production_reports (source, external_event_id) WHERE external_event_id IS NOT NULL');

        // Quality inspection decides what happens to units that have been reported and not yet judged.
        Schema::create('quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete();
            $table->date('inspected_on');
            $table->decimal('accepted_qty', 18, 6)->default(0);
            $table->decimal('rejected_qty', 18, 6)->default(0);
            $table->decimal('rework_qty', 18, 6)->default(0);
            $table->string('reject_reason', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('order_id');
        });
        DB::statement('ALTER TABLE quality_inspections ADD CONSTRAINT quality_inspections_check CHECK (accepted_qty >= 0 AND rejected_qty >= 0 AND rework_qty >= 0 AND accepted_qty + rejected_qty + rework_qty > 0)');

        Schema::create('downtime_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->nullable()->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('machine_id')->constrained('machines')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('reason_id')->constrained('downtime_reasons')->restrictOnDelete();
            $table->date('shift_date');                              // the day the shift started (a night shift's after-midnight hours belong to the day before)
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at');
            $table->decimal('minutes', 10, 2);
            $table->string('notes', 500)->nullable();
            $table->string('source', 10)->default('manual');         // manual | device
            $table->string('external_event_id', 100)->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['machine_id', 'started_at']);
            $table->index(['shift_date', 'shift_id']);
        });
        DB::statement("ALTER TABLE downtime_events ADD CONSTRAINT downtime_events_check CHECK (source IN ('manual','device') AND ended_at > started_at AND minutes > 0)");
        DB::statement('CREATE UNIQUE INDEX downtime_events_external_unique ON downtime_events (source, external_event_id) WHERE external_event_id IS NOT NULL');

        Schema::create('finished_goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->decimal('unit_cost', 18, 6);                     // LKR per base unit, from the material cost held by the order
            $table->decimal('value', 18, 4);                         // LKR taken into stock
            $table->date('received_on');
            $table->string('lot_no', 60)->nullable();
            $table->unsignedBigInteger('stock_document_id');
            $table->string('stock_document_no', 40);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('order_id');
        });
        DB::statement('ALTER TABLE finished_goods_receipts ADD CONSTRAINT finished_goods_receipts_check CHECK (qty > 0 AND unit_cost >= 0 AND value >= 0)');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER quality_inspections_immutable BEFORE UPDATE OR DELETE ON quality_inspections FOR EACH ROW EXECUTE FUNCTION mfg_refuse_change();
CREATE TRIGGER finished_goods_receipts_immutable BEFORE UPDATE OR DELETE ON finished_goods_receipts FOR EACH ROW EXECUTE FUNCTION mfg_refuse_change();
CREATE TRIGGER production_reports_no_delete BEFORE DELETE ON production_reports FOR EACH ROW EXECUTE FUNCTION mfg_refuse_change();
CREATE TRIGGER downtime_events_no_delete BEFORE DELETE ON downtime_events FOR EACH ROW EXECUTE FUNCTION mfg_refuse_change();

-- A report or downtime entry keeps what was entered; the only change allowed is to void it, once.
CREATE OR REPLACE FUNCTION mfg_entry_guard() RETURNS trigger AS $$
BEGIN
    IF OLD.voided_at IS NOT NULL THEN
        RAISE EXCEPTION 'A voided entry cannot be changed';
    END IF;
    IF to_jsonb(NEW) - 'voided_at' - 'void_reason' - 'voided_by' IS DISTINCT FROM to_jsonb(OLD) - 'voided_at' - 'void_reason' - 'voided_by' THEN
        RAISE EXCEPTION 'An entry cannot be changed; void it and enter it again';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER production_reports_guard BEFORE UPDATE ON production_reports FOR EACH ROW EXECUTE FUNCTION mfg_entry_guard();
CREATE TRIGGER downtime_events_guard BEFORE UPDATE ON downtime_events FOR EACH ROW EXECUTE FUNCTION mfg_entry_guard();
SQL);
        }
    }

    public function down(): void
    {
        foreach (['finished_goods_receipts', 'downtime_events', 'quality_inspections', 'production_reports'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['completed_at', 'fg_value', 'variance_value']);
        });
        Schema::dropIfExists('downtime_reasons');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_entry_guard() CASCADE');
        }
    }
};
