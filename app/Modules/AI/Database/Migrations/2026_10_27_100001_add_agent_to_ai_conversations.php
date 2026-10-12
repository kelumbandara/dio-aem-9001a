<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which agent (sales, purchasing, …) the conversation is with; fixed when it starts.
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('agent', 40)->default('assistant');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('agent');
        });
    }
};
