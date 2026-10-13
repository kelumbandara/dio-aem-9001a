<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The wording of each kind of message, per channel and language. `sensitive` ones (money, legal) need an approval before they
        // are sent; for WhatsApp, `provider_template` is the name of the template Meta approved for business-started conversations.
        Schema::create('comm_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('key', 60);
            $table->string('channel', 10);
            $table->string('locale', 5)->default('en');
            $table->string('purpose', 20)->default('transactional');
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('provider_template', 100)->nullable();
            $table->string('provider_language', 10)->nullable();
            $table->jsonb('param_order')->nullable();            // the placeholder names in the order Meta's template numbers them
            $table->boolean('sensitive')->default(false);
            $table->boolean('approved')->default(true);          // WhatsApp: Meta has approved the template; e-mail needs none
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['key', 'channel', 'locale']);
        });
        DB::statement("ALTER TABLE comm_templates ADD CONSTRAINT comm_templates_check CHECK (channel IN ('email','whatsapp') AND purpose IN ('transactional','marketing'))");

        // Who has allowed or refused which channel. A history that is only added to: the newest line for an address and channel is
        // what counts, and it is always possible to show when and why it changed. The address, not the person, is what opts out.
        Schema::create('comm_consents', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);
            $table->string('address', 190);                      // lower-case e-mail, or the phone number as digits with country code
            $table->string('status', 10);
            $table->string('source', 30);
            $table->string('note', 255)->nullable();
            $table->string('party_type', 20)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['channel', 'address', 'id']);
        });
        DB::statement("ALTER TABLE comm_consents ADD CONSTRAINT comm_consents_check CHECK (channel IN ('email','whatsapp') AND status IN ('opted_in','opted_out'))");

        Schema::create('comm_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('direction', 3);
            $table->string('channel', 10);
            $table->string('status', 20);
            $table->string('party_type', 20)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('address', 190);
            $table->string('template_key', 60)->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->jsonb('params')->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('drafted_by_ai')->default(false);
            $table->string('approval_request_id', 64)->nullable();
            $table->string('related_type', 60)->nullable();
            $table->string('related_id', 64)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index(['party_type', 'party_id']);
            $table->index(['channel', 'provider_message_id']);
            $table->index(['channel', 'address', 'created_at']);
        });
        DB::statement("ALTER TABLE comm_messages ADD CONSTRAINT comm_messages_check CHECK (direction IN ('out','in') AND channel IN ('email','whatsapp') AND status IN ('draft','awaiting_approval','queued','sending','sent','delivered','read','failed','cancelled','received'))");

        // A phone number or e-mail address proven to belong to a user (they received a code on it and typed it back).
        Schema::create('comm_identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('channel', 10);
            $table->string('address', 190);
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['channel', 'address']);              // one person per number
            $table->index('user_id');
        });

        Schema::create('comm_identity_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('address', 190);
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION comm_refuse_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% on % is not allowed: consent history is only ever added to', TG_OP, TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER comm_consents_immutable BEFORE UPDATE OR DELETE ON comm_consents FOR EACH ROW EXECUTE FUNCTION comm_refuse_change();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comm_identity_challenges');
        Schema::dropIfExists('comm_identities');
        Schema::dropIfExists('comm_messages');
        Schema::dropIfExists('comm_consents');
        Schema::dropIfExists('comm_templates');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS comm_refuse_change()');
        }
    }
};
