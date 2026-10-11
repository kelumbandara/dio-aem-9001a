<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('lot_no', 60);
            $table->date('expiry_date')->nullable()->index();
            $table->date('received_on');
            $table->timestamps();
            $table->unique(['item_id', 'lot_no']);
        });

        // A stock document is posted the moment it is saved (there is no draft): it either changes stock or does not exist.
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('document_no', 40)->unique();
            $table->string('doc_type', 12);                  // receipt|issue|transfer|adjustment
            $table->string('status', 10)->default('posted'); // posted|reversed
            $table->date('posting_date')->index();
            $table->string('reason', 30);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            // Set when another module posts the movement (a delivery, a goods receipt…); one document per source and type.
            $table->string('source_type', 60)->nullable();
            $table->string('source_id', 60)->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->decimal('total_value', 18, 4)->default(0); // sum of absolute line values, LKR
            $table->string('approval_request_id', 36)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['doc_type', 'posting_date']);
        });
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_type_check CHECK (doc_type IN ('receipt','issue','transfer','adjustment'))");
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_status_check CHECK (status IN ('posted','reversed'))");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX stock_documents_one_per_source ON stock_documents (source_type, source_id, doc_type) WHERE source_type IS NOT NULL');
        }

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('stock_documents')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('uom_code', 10);                   // as entered, kept for display
            $table->decimal('qty', 18, 6);                    // in the entered unit
            $table->decimal('base_qty', 18, 6);               // in the item's base unit (what stock is kept in)
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete();
            $table->decimal('unit_cost', 18, 6)->nullable();  // per entered unit, receipts and adjustment gains
            $table->decimal('value', 18, 4);                  // LKR, actual (what the ledger recorded)
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'line_no']);
            $table->index('item_id');
        });
        DB::statement('ALTER TABLE stock_document_lines ADD CONSTRAINT stock_document_lines_qty_check CHECK (qty > 0 AND base_qty > 0 AND value >= 0)');

        if (DB::getDriverName() === 'pgsql') {
            // Posted documents and their lines never change, except that a document gets marked reversed (status + reversed_by_id).
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION stock_document_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'stock documents cannot be deleted';
                    END IF;
                    IF (to_jsonb(NEW) - 'status' - 'reversed_by_id' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reversed_by_id' - 'updated_at') THEN
                        RAISE EXCEPTION 'posted stock documents cannot be changed; reverse them instead';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('CREATE TRIGGER stock_documents_guard BEFORE UPDATE OR DELETE ON stock_documents FOR EACH ROW EXECUTE FUNCTION stock_document_guard()');
            DB::unprepared('CREATE TRIGGER stock_document_lines_append_only BEFORE UPDATE OR DELETE ON stock_document_lines FOR EACH ROW EXECUTE FUNCTION prevent_row_mutation()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_document_lines');
        Schema::dropIfExists('stock_documents');
        Schema::dropIfExists('lots');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS stock_document_guard()');
        }
    }
};
