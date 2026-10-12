<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many good units of a product a shift is expected to make. The shift report sets what was made against it.
        Schema::create('shift_targets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('target_qty', 18, 6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['shift_id', 'item_id']);
        });
        DB::statement('ALTER TABLE shift_targets ADD CONSTRAINT shift_targets_check CHECK (target_qty > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_targets');
    }
};
