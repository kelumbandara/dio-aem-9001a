<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('attachable_type', 100); // morph alias, see Relation::enforceMorphMap
            $table->unsignedBigInteger('attachable_id');
            $table->string('disk', 30);
            $table->string('path')->unique(); // random name; the client's file name is never a path
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime_type', 100); // detected from content, not taken from the client
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->string('scan_status', 20)->default('pending'); // pending|clean|infected|skipped
            $table->timestamp('scanned_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
