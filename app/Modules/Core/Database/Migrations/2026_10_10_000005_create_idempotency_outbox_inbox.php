<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 150); // e.g. user:42
            $table->string('key', 255);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable(); // null while in flight
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });

        // Transactional outbox: written in the same transaction as the business change.
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 150)->index();
            $table->string('aggregate_type', 150)->nullable();
            $table->string('aggregate_id', 64)->nullable();
            $table->json('payload');
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Inbound dedupe for webhooks / device messages.
        Schema::create('inbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 100);
            $table->string('message_id', 255);
            $table->char('payload_hash', 64)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_events');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_keys');
    }
};
