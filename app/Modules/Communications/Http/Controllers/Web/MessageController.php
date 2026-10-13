<?php

namespace App\Modules\Communications\Http\Controllers\Web;

use App\Modules\Communications\Http\Controllers\Concerns\PresentsMessages;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;
use App\Modules\Communications\Services\Address;
use App\Modules\Communications\Services\MessageService;
use App\Modules\Communications\Services\TemplateRenderer;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MessageController extends Controller
{
    use PresentsMessages;

    public function index(Request $request): View
    {
        $this->needs($request, 'comm.message.view');
        $f = $request->validate(['status' => ['nullable', Rule::in(Message::STATUSES)], 'channel' => ['nullable', Rule::in(['email', 'whatsapp'])]]);
        $page = Message::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('communications::messages.index', ['page' => $page, 'filters' => $f, 'statuses' => Message::STATUSES, 'canDraft' => $request->user()->can('comm.message.draft')]);
    }

    /** Step one chooses channel and template; once chosen, the page shows the values that template needs. */
    public function create(Request $request, TemplateRenderer $renderer): View
    {
        $this->needs($request, 'comm.message.draft');
        $channel = in_array($request->query('channel'), ['email', 'whatsapp'], true) ? (string) $request->query('channel') : 'email';
        $templates = Template::query()->where('channel', $channel)->where('is_active', true)->where('locale', 'en')->where('key', '!=', 'identity_code')
            ->when($channel === 'whatsapp', fn ($q) => $q->where('approved', true))->orderBy('key')->get();
        $chosen = $templates->firstWhere('key', $request->query('template'));
        $fields = $chosen === null ? [] : array_values(array_diff($renderer->placeholders($chosen->body.' '.(string) $chosen->subject), ['company', 'name']));

        return view('communications::messages.create', ['channel' => $channel, 'templates' => $templates, 'chosen' => $chosen, 'fields' => $fields]);
    }

    public function store(Request $request, MessageService $messages): RedirectResponse
    {
        $this->needs($request, 'comm.message.draft');
        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'whatsapp'])], 'template' => ['required', 'string', 'max:60'], 'party_type' => ['required', Rule::in(['customer', 'supplier'])], 'party' => ['required', 'string', 'max:60'],
            'params' => ['nullable', 'array'], 'params.*' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $message = $messages->draft($data, $request->user()->id);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('comm.messages.show', $message->public_id)->with('status', __('communications::ui.flash.drafted'));
    }

    public function show(Request $request, Message $message): View
    {
        $this->needs($request, 'comm.message.view');

        return view('communications::messages.show', [
            'm' => $message, 'to' => Address::mask($message->channel, $message->address),
            'canSend' => $request->user()->can('comm.message.send'), 'canDraft' => $request->user()->can('comm.message.draft'),
        ]);
    }

    public function send(Request $request, Message $message, MessageService $messages): RedirectResponse
    {
        $this->needs($request, 'comm.message.send');

        try {
            $sent = $messages->send($message, $request->user()->id);
        } catch (BusinessException $e) {
            return back()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('comm.messages.show', $sent->public_id)->with('status', __($sent->status === 'awaiting_approval' ? 'communications::ui.flash.awaiting_approval' : 'communications::ui.flash.queued'));
    }

    public function cancel(Request $request, Message $message, MessageService $messages): RedirectResponse
    {
        $this->needs($request, 'comm.message.draft');

        try {
            $messages->cancel($message, $request->user()->id);
        } catch (BusinessException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('comm.messages.show', $message->public_id)->with('status', __('communications::ui.flash.cancelled'));
    }
}
