<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Modules\Finance\Actions\PostManualJournal;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Modules\Finance\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Finance\Http\Requests\PostJournalRequest;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\JournalInput;
use App\Modules\Finance\Services\JournalPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JournalController extends Controller
{
    use ReportsBusinessErrors;

    public function index(Request $request, JournalPresenter $presenter): View
    {
        $this->authorize('finance.view');
        $kind = in_array($request->query('kind'), ['manual', 'system', 'reversal', 'opening'], true) ? $request->query('kind') : null;
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from') ?: null;
        $to = $request->query('to') ?: null;

        $page = JournalEntry::query()->when($kind, fn ($query) => $query->where('kind', $kind))
            ->when($from, fn ($query) => $query->where('entry_date', '>=', $from))->when($to, fn ($query) => $query->where('entry_date', '<=', $to))
            ->when($q !== '', function ($query) use ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
                $query->where(fn ($w) => $w->whereRaw('lower(journal_no) like ?', [$term])->orWhereRaw('lower(description) like ?', [$term])->orWhereRaw("lower(coalesce(reference, '')) like ?", [$term]));
            })->orderByDesc('entry_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('finance::journals.index', ['page' => $page, 'rows' => $presenter->headers($page->getCollection()), 'kind' => $kind, 'q' => $q, 'from' => $from, 'to' => $to]);
    }

    public function create(): View
    {
        $this->authorize('finance.journal.post');

        return view('finance::journals.form', ['accounts' => Account::query()->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['code', 'name'])]);
    }

    public function store(PostJournalRequest $request, JournalInput $input, PostManualJournal $post): RedirectResponse
    {
        return $this->attempt(function () use ($request, $input, $post) {
            $result = $post($input->request($request->validated(), $request->user()->id), $request->user()->id, 'web');

            if (! $result instanceof PostedJournal) {
                return redirect()->route('finance.journals.index')->with('status', __('finance::ui.flash.awaiting_approval', ['document' => $result['document_no']]));
            }

            return redirect()->route('finance.journals.show', $result->publicId)->with('status', __('finance::ui.flash.posted', ['document' => $result->journalNo]));
        });
    }

    public function show(JournalEntry $journal, JournalPresenter $presenter): View
    {
        $this->authorize('finance.view');

        return view('finance::journals.show', ['j' => $presenter->full($journal), 'model' => $journal]);
    }

    public function reverse(Request $request, JournalEntry $journal, JournalPoster $poster): RedirectResponse
    {
        $this->authorize('finance.reverse');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $journal, $poster, $data) {
            $reversal = $poster->reverse($journal->id, $data['note'] ?? null, $request->user()->id);

            return redirect()->route('finance.journals.show', $reversal->publicId)->with('status', __('finance::ui.flash.reversed', ['document' => $journal->journal_no]));
        });
    }
}
