<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->string('actor_type', 10)->default('system')->after('correlation_id'); // user|system|ai
            $table->unsignedBigInteger('actor_id')->nullable()->after('actor_type');
            $table->unsignedBigInteger('branch_id')->nullable()->after('actor_id');
            $table->timestamp('failed_at')->nullable()->after('processed_at'); // gave up after max attempts
            $table->index(['processed_at', 'failed_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropIndex(['processed_at', 'failed_at', 'available_at']);
            $table->dropColumn(['actor_type', 'actor_id', 'branch_id', 'failed_at']);
        });
    }
};
