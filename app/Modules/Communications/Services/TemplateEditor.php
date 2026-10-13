<?php

namespace App\Modules\Communications\Services;

use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Models\Template;
use App\Modules\Core\Contracts\AuditLogger;

/**
 * Changing the wording of a template. Rules: a body may only use values the message knows (an unknown {value} could never be
 * filled); WhatsApp's own fields (approved, provider template) mean nothing for e-mail and are ignored there; a template that
 * once carried the "sensitive" mark can only lose it by a person with the template permission, and every change is audited.
 */
final class TemplateEditor
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CommunicationsException comm.placeholder_unknown | comm.template_incomplete
     */
    public function update(Template $template, array $data): Template
    {
        $known = ['company', 'name', ...(array) $template->param_order, ...$this->renderer->placeholders($template->body), ...$this->renderer->placeholders((string) $template->subject)];
        foreach (['body', 'subject'] as $field) {
            foreach ($this->renderer->placeholders((string) ($data[$field] ?? '')) as $p) {
                if (! in_array($p, $known, true)) {
                    throw CommunicationsException::make('comm.placeholder_unknown', "{{$p}} is not a value this message knows.", 422, $field);
                }
            }
        }
        if ($template->channel === 'email') {
            unset($data['approved'], $data['provider_template'], $data['provider_language']);
        }
        if (($data['approved'] ?? false) === true && (string) ($data['provider_template'] ?? $template->provider_template) === '') {
            throw CommunicationsException::make('comm.template_incomplete', 'Name the template WhatsApp approved before marking it approved.', 422, 'provider_template');
        }

        $old = $template->only(['subject', 'body', 'sensitive', 'approved', 'is_active', 'provider_template']);
        $template->fill($data)->save();
        $this->audit->record('comm.template_changed', $template, $old, $template->only(array_keys($old)));

        return $template->refresh();
    }
}
