<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ where and when work happens
        Schema::create('work_centers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('capacity_hours_per_day', 6, 2)->default(8);
            $table->decimal('hourly_rate', 14, 4)->default(0);   // LKR per hour; used by costing in Sprint 9
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE work_centers ADD CONSTRAINT work_centers_check CHECK (capacity_hours_per_day > 0 AND capacity_hours_per_day <= 24 AND hourly_rate >= 0)');

        Schema::create('production_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('work_center_id')->constrained('work_centers')->restrictOnDelete();
            $table->foreignId('production_line_id')->nullable()->constrained('production_lines')->nullOnDelete();
            $table->string('status', 12)->default('available');   // available | maintenance | retired
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE machines ADD CONSTRAINT machines_status_check CHECK (status IN ('available','maintenance','retired'))");

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->time('start_time');
            $table->time('end_time');                              // earlier than start_time = the shift ends the next day
            $table->string('days', 7)->default('1111110');         // Monday..Sunday, 1 = works
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_check CHECK (start_time <> end_time AND days ~ '^[01]{7}$')");

        // ------------------------------------------------------------------ bills of materials (versioned) with their routing
        Schema::create('bom_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status', 10)->default('draft');       // draft | active | retired
            $table->decimal('base_qty', 18, 6)->default(1);       // the components below make this much of the item (in its base unit)
            $table->string('description', 255)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);       // optimistic lock for editing a draft
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['item_id', 'version_no']);
        });
        DB::statement("ALTER TABLE bom_versions ADD CONSTRAINT bom_versions_check CHECK (status IN ('draft','active','retired') AND base_qty > 0)");
        DB::statement("CREATE UNIQUE INDEX bom_versions_one_active ON bom_versions (item_id) WHERE status = 'active'");

        Schema::create('bom_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_version_id')->constrained('bom_versions')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('component_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 18, 6);                        // per `base_qty` of the finished item, in `uom_id`
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('scrap_pct', 7, 3)->default(0);       // extra to allow for loss, percent of the quantity
            $table->string('notes', 255)->nullable();
            $table->unique(['bom_version_id', 'line_no']);
            $table->unique(['bom_version_id', 'component_item_id']);
        });
        DB::statement('ALTER TABLE bom_components ADD CONSTRAINT bom_components_check CHECK (qty > 0 AND scrap_pct >= 0 AND scrap_pct < 100)');

        Schema::create('bom_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_version_id')->constrained('bom_versions')->cascadeOnDelete();
            $table->unsignedSmallInteger('op_no');                // 10, 20, 30 ... the order of work
            $table->string('name', 150);
            $table->foreignId('work_center_id')->constrained('work_centers')->restrictOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->restrictOnDelete();
            $table->decimal('setup_minutes', 10, 2)->default(0);  // once per order
            $table->decimal('run_minutes', 12, 4)->default(0);    // per unit of the finished item
            $table->string('notes', 255)->nullable();
            $table->unique(['bom_version_id', 'op_no']);
        });
        DB::statement('ALTER TABLE bom_operations ADD CONSTRAINT bom_operations_check CHECK (op_no > 0 AND setup_minutes >= 0 AND run_minutes >= 0)');

        // ------------------------------------------------------------------ production orders
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('order_no', 40)->unique();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('bom_version_id')->constrained('bom_versions')->restrictOnDelete();
            $table->string('status', 12)->default('draft');       // draft | released | in_progress | completed | closed | cancelled
            // Six quantities that are never mixed up (docs/architecture/mes-iot.md); this sprint only fills `planned_qty`.
            $table->decimal('planned_qty', 18, 6);
            $table->decimal('reported_qty', 18, 6)->default(0);
            $table->decimal('accepted_qty', 18, 6)->default(0);
            $table->decimal('rejected_qty', 18, 6)->default(0);
            $table->decimal('rework_qty', 18, 6)->default(0);
            $table->decimal('received_qty', 18, 6)->default(0);
            $table->date('planned_start');
            $table->date('planned_end');
            $table->foreignId('production_line_id')->nullable()->constrained('production_lines')->nullOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();   // components are taken from here
            $table->foreignId('output_warehouse_id')->constrained('warehouses')->restrictOnDelete();   // finished goods go here (Sprint 9)
            $table->text('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'planned_start']);
        });
        DB::statement("ALTER TABLE production_orders ADD CONSTRAINT production_orders_check CHECK (
            status IN ('draft','released','in_progress','completed','closed','cancelled')
            AND planned_qty > 0 AND reported_qty >= 0 AND accepted_qty >= 0 AND rejected_qty >= 0 AND rework_qty >= 0 AND received_qty >= 0
            AND accepted_qty + rejected_qty + rework_qty <= reported_qty AND received_qty <= accepted_qty
            AND planned_end >= planned_start)");

        Schema::create('production_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('required_qty', 18, 6);               // in the component's base unit, scrap allowance included
            $table->decimal('issued_qty', 18, 6)->default(0);
            $table->decimal('returned_qty', 18, 6)->default(0);
            $table->decimal('issued_value', 18, 4)->default(0);   // LKR taken out of stock for this line
            $table->decimal('returned_value', 18, 4)->default(0);   // may exceed issued_value by a rounding cent when everything is returned
            $table->unique(['order_id', 'line_no']);
            $table->unique(['order_id', 'item_id']);
        });
        DB::statement('ALTER TABLE production_order_materials ADD CONSTRAINT production_order_materials_check CHECK (required_qty >= 0 AND issued_qty >= 0 AND returned_qty >= 0 AND returned_qty <= issued_qty AND issued_value >= 0 AND returned_value >= 0)');

        Schema::create('production_order_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('op_no');
            $table->string('name', 150);
            $table->foreignId('work_center_id')->constrained('work_centers')->restrictOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->restrictOnDelete();
            $table->decimal('planned_setup_minutes', 10, 2)->default(0);
            $table->decimal('planned_run_minutes', 14, 4)->default(0);   // run minutes per unit x planned quantity
            $table->string('status', 12)->default('pending');            // pending | in_progress | done (filled by Sprint 9)
            $table->unique(['order_id', 'op_no']);
        });

        // Every issue to and return from an order, one row each; never changed afterwards (a mistake is put right with a return).
        Schema::create('production_material_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('production_order_materials')->restrictOnDelete();
            $table->string('kind', 8);                             // issue | return
            $table->decimal('qty', 18, 6);                         // base unit, positive
            $table->decimal('value', 18, 4);                       // LKR, positive: what stock gave up (issue) or took back (return)
            $table->unsignedBigInteger('stock_document_id');
            $table->string('stock_document_no', 40);
            $table->date('posted_on');
            $table->string('reason', 255)->nullable();             // why: over-issue, or a return
            $table->boolean('over_issue')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'material_id']);
        });
        DB::statement("ALTER TABLE production_material_movements ADD CONSTRAINT production_material_movements_check CHECK (kind IN ('issue','return') AND qty > 0 AND value >= 0)");

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION mfg_refuse_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% on % is not allowed: material movements are kept as they were posted', TG_OP, TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER production_material_movements_immutable BEFORE UPDATE OR DELETE ON production_material_movements FOR EACH ROW EXECUTE FUNCTION mfg_refuse_change();

-- An active or retired BOM version is history that production orders rest on: its content never changes.
CREATE OR REPLACE FUNCTION mfg_bom_version_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'Only a draft BOM version can be deleted';
        END IF;
        RETURN OLD;
    END IF;
    IF (NEW.item_id, NEW.version_no, NEW.base_qty) IS DISTINCT FROM (OLD.item_id, OLD.version_no, OLD.base_qty) AND OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'An active or retired BOM version cannot be changed; make a new version';
    END IF;
    IF OLD.status = 'retired' AND NEW.status <> 'retired' THEN
        RAISE EXCEPTION 'A retired BOM version cannot be used again; copy it into a new version';
    END IF;
    IF OLD.status = 'active' AND NEW.status = 'draft' THEN
        RAISE EXCEPTION 'An active BOM version cannot go back to draft';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bom_versions_guard BEFORE UPDATE OR DELETE ON bom_versions FOR EACH ROW EXECUTE FUNCTION mfg_bom_version_guard();

CREATE OR REPLACE FUNCTION mfg_bom_content_guard() RETURNS trigger AS $$
DECLARE v_status text;
        v_id bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN v_id := OLD.bom_version_id; ELSE v_id := NEW.bom_version_id; END IF;
    SELECT status INTO v_status FROM bom_versions WHERE id = v_id;
    -- the parent row may already be gone when a draft is deleted (cascade): allow that
    IF v_status IS NOT NULL AND v_status <> 'draft' THEN
        RAISE EXCEPTION 'The components and routing of an active or retired BOM version cannot be changed; make a new version';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bom_components_guard BEFORE INSERT OR UPDATE OR DELETE ON bom_components FOR EACH ROW EXECUTE FUNCTION mfg_bom_content_guard();
CREATE TRIGGER bom_operations_guard BEFORE INSERT OR UPDATE OR DELETE ON bom_operations FOR EACH ROW EXECUTE FUNCTION mfg_bom_content_guard();
SQL);
        }
    }

    public function down(): void
    {
        foreach (['production_material_movements', 'production_order_operations', 'production_order_materials', 'production_orders', 'bom_operations', 'bom_components', 'bom_versions', 'shifts', 'machines', 'production_lines', 'work_centers'] as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_change(), mfg_bom_version_guard(), mfg_bom_content_guard() CASCADE');
        }
    }
};
