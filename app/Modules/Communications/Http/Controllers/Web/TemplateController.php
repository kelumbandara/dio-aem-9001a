<?php

namespace App\Modules\Communications\Http\Controllers\Web;

use App\Modules\Communications\Http\Controllers\Concerns\PresentsMessages;
use App\Modules\Communications\Models\Template;
use App\Modules\Communications\Services\TemplateEditor;
use App\Modules\Communications\Services\TemplateRenderer;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TemplateController extends Controller
{
    use PresentsMessages;

    public function index(Request $request): View
    {
        $this->needs($request, 'comm.message.view');

        return view('communications::templates.index', ['templates' => Template::query()->orderBy('key')->orderBy('channel')->orderBy('locale')->get(), 'canManage' => $request->user()->can('comm.template.manage')]);
    }

    public function edit(Request $request, Template $template, TemplateRenderer $renderer): View
    {
        $this->needs($request, 'comm.template.manage');

        return view('communications::templates.edit', ['t' => $template, 'placeholders' => array_values(array_unique(['company', 'name', ...(array) $template->param_order, ...$renderer->placeholders($template->body)]))]);
    }

    public function update(Request $request, Template $template, TemplateEditor $editor): RedirectResponse
    {
        $this->needs($request, 'comm.template.manage');
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:200'], 'body' => ['required', 'string', 'max:4000'], 'provider_template' => ['nullable', 'string', 'max:100'], 'provider_language' => ['nullable', 'string', 'max:10']]);
        $data['sensitive'] = $request->boolean('sensitive');
        $data['is_active'] = $request->boolean('is_active');
        $data['approved'] = $request->boolean('approved');

        try {
            $editor->update($template, $data);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('comm.templates.index')->with('status', __('communications::ui.flash.template_saved'));
    }
}
