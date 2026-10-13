<?php

namespace App\Modules\Communications\Database\Seeders;

use App\Modules\Communications\Models\Template;
use Illuminate\Database\Seeder;

/**
 * The starting message templates. Creates only the ones that are missing (never overwrites a template that was edited).
 *
 * WhatsApp templates start as **not approved**: Meta must approve each one (name, language, wording) before it can be used, and
 * only then does an administrator tick "approved" on the template screen. Until then WhatsApp messages of that kind are refused
 * with a clear reason. Money and free-text messages are marked sensitive: they need an approval before they are sent.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $t) {
            Template::query()->firstOrCreate(['key' => $t['key'], 'channel' => $t['channel'], 'locale' => $t['locale']], $t);
        }
    }

    /** @return list<array<string, mixed>> */
    private function templates(): array
    {
        $reminder = "Dear {name},\n\nThis is a friendly reminder from {company} that {document_no} for {amount}, due on {due_date}, is still open.\n\nIf you have already paid, please ignore this message. If you have a question, just reply.\n\nThank you,\n{company}";
        $notice = "Dear {name},\n\nYour {document_type} {document_no} from {company} is ready.\n\nThank you,\n{company}";

        return [
            ['key' => 'payment_reminder', 'channel' => 'email', 'locale' => 'en', 'purpose' => 'transactional', 'subject' => 'Payment reminder: {document_no}', 'body' => $reminder, 'sensitive' => true, 'approved' => true],
            ['key' => 'payment_reminder', 'channel' => 'whatsapp', 'locale' => 'en', 'purpose' => 'transactional', 'body' => 'Dear {name}, this is a reminder from {company} that {document_no} for {amount}, due on {due_date}, is still open. If you have already paid, please ignore this message.',
                'provider_template' => 'payment_reminder', 'provider_language' => 'en', 'param_order' => ['name', 'company', 'document_no', 'amount', 'due_date'], 'sensitive' => true, 'approved' => false],
            ['key' => 'document_notice', 'channel' => 'email', 'locale' => 'en', 'purpose' => 'transactional', 'subject' => 'Your {document_type} {document_no} from {company}', 'body' => $notice, 'sensitive' => false, 'approved' => true],
            ['key' => 'document_notice', 'channel' => 'whatsapp', 'locale' => 'en', 'purpose' => 'transactional', 'body' => 'Dear {name}, your {document_type} {document_no} from {company} is ready.',
                'provider_template' => 'document_notice', 'provider_language' => 'en', 'param_order' => ['name', 'document_type', 'document_no', 'company'], 'sensitive' => false, 'approved' => false],
            ['key' => 'general_notice', 'channel' => 'email', 'locale' => 'en', 'purpose' => 'transactional', 'subject' => '{subject}', 'body' => "Dear {name},\n\n{message}\n\nThank you,\n{company}", 'sensitive' => true, 'approved' => true],
            ['key' => 'identity_code', 'channel' => 'email', 'locale' => 'en', 'purpose' => 'transactional', 'subject' => 'Your {company} verification code', 'body' => 'Your {company} verification code is {code}. It works for {minutes} minutes. If you did not ask for it, ignore this message.', 'sensitive' => false, 'approved' => true],
            ['key' => 'identity_code', 'channel' => 'whatsapp', 'locale' => 'en', 'purpose' => 'transactional', 'body' => '{code} is your {company} verification code. It works for {minutes} minutes.',
                'provider_template' => 'identity_code', 'provider_language' => 'en', 'param_order' => ['code'], 'sensitive' => false, 'approved' => false],
        ];
    }
}
