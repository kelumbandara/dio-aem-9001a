<?php

namespace App\Modules\Communications\Http\Controllers\Api;

use App\Modules\Communications\Http\Controllers\Concerns\PresentsMessages;
use App\Modules\Communications\Models\Template;
use App\Modules\Communications\Services\TemplateEditor;
use App\Modules\Communications\Services\TemplateRenderer;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    use PresentsMessages;

    public function index(Request $request): JsonResponse
    {
        $this->needs($request, 'comm.message.view');

        return response()->json(['data' => Template::query()->orderBy('key')->orderBy('channel')->orderBy('locale')->get()->map(fn (Template $t) => $this->row($t))->values()]);
    }

    public function update(Request $request, Template $template, TemplateEditor $editor): JsonResponse
    {
        $this->needs($request, 'comm.template.manage');
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'], 'body' => ['sometimes', 'required', 'string', 'max:4000'], 'sensitive' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
            'approved' => ['sometimes', 'boolean'], 'provider_template' => ['nullable', 'string', 'max:100'], 'provider_language' => ['nullable', 'string', 'max:10'],
        ]);

        return response()->json(['data' => $this->row($editor->update($template, $data))]);
    }

    /** @return array<string, mixed> */
    private function row(Template $t): array
    {
        return [
            'id' => $t->public_id, 'key' => $t->key, 'channel' => $t->channel, 'locale' => $t->locale, 'purpose' => $t->purpose, 'subject' => $t->subject, 'body' => $t->body,
            'sensitive' => $t->sensitive, 'approved' => $t->approved, 'provider_template' => $t->provider_template, 'is_active' => $t->is_active, 'placeholders' => app(TemplateRenderer::class)->placeholders($t->body),
        ];
    }
}
