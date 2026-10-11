<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            // Off: every movement uses the warehouse's single default location. On: people choose a location (bin/rack).
            $table->boolean('uses_locations')->default(false);
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
        });

        // Exactly one default location per warehouse (the one used when locations are switched off).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX locations_one_default ON locations (warehouse_id) WHERE is_default');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('warehouses');
    }
};
