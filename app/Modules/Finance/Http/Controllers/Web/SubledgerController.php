<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Modules\Finance\Actions\PostSettlement;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\SettlementPresenter;
use App\Modules\Finance\Services\SettlementService;
use App\Modules\Finance\Services\SubledgerQueries;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\DTOs\PartyInfo;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Receivables and payables screens; the side (receivable / payable) comes from the route's defaults. */
class SubledgerController extends Controller
{
    use ReportsBusinessErrors;

    private const VIEW = ['receivable' => 'finance.ar.view', 'payable' => 'finance.ap.view'];

    private const POST = ['receivable' => 'finance.ar.receive', 'payable' => 'finance.ap.pay'];

    public function __construct(
        private readonly SubledgerQueries $queries,
        private readonly PartyDirectory $parties,
        private readonly SettlementPresenter $presenter,
    ) {}

    public function index(Request $request): View
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);
        $party = $request->query('party') ? $this->partyOrNull($side, (string) $request->query('party')) : null;

        return view('finance::subledger.index', [
            'side' => $side, 'items' => $this->queries->openItems($side, $party->id ?? ($request->query('party') ? -1 : null)),
            'ageing' => $this->queries->ageing($side), 'tie' => $this->queries->tieOut($side), 'unallocated' => $this->queries->unallocated($side),
            'party' => (string) $request->query('party', ''), 'canPost' => $request->user()->can(self::POST[$side]),
        ]);
    }

    public function settlements(Request $request): View
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);
        $page = Settlement::query()->where('side', $side)->orderByDesc('settled_on')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('finance::subledger.settlements', ['side' => $side, 'page' => $page, 'rows' => $this->presenter->many($page->getCollection()), 'canPost' => $request->user()->can(self::POST[$side])]);
    }

    public function create(Request $request): View
    {
        $side = $this->side($request);
        $this->authorize(self::POST[$side]);
        $party = $request->query('party') ? $this->partyOrNull($side, (string) $request->query('party')) : null;

        return view('finance::subledger.form', [
            'side' => $side, 'banks' => BankAccount::query()->where('is_active', true)->where('currency', 'LKR')->orderBy('id')->get(['public_id', 'name', 'kind']),
            'party' => $party, 'methods' => Settlement::METHODS,
            'openItems' => $party ? OpenItem::query()->where('side', $side)->where('party_id', $party->id)->where('open_amount', '>', 0)->orderBy('due_date')->get() : collect(),
        ]);
    }

    public function store(Request $request, PostSettlement $post): RedirectResponse
    {
        $side = $this->side($request);
        $this->authorize(self::POST[$side]);
        $data = $request->validate([
            'party' => ['required', 'string', 'max:30'], 'bank_account' => ['required', 'uuid'], 'date' => ['nullable', 'date_format:Y-m-d'], 'amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'method' => ['required', 'in:'.implode(',', Settlement::METHODS)], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500'],
            'allocations' => ['sometimes', 'array', 'max:200'], 'allocations.*.open_item' => ['nullable', 'uuid'], 'allocations.*.amount' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
        ]);

        return $this->attempt(function () use ($request, $post, $side, $data) {
            $party = $this->partyOrNull($side, $data['party']) ?? throw FinanceException::make('finance.unknown_code', 'Unknown party code.', 422, 'party');
            $bank = BankAccount::query()->where('public_id', $data['bank_account'])->first() ?? throw FinanceException::make('finance.bank_invalid', 'Choose a bank or cash account.', 422, 'bank_account');
            $allocations = [];
            foreach (array_values((array) ($data['allocations'] ?? [])) as $i => $row) {
                if (($row['open_item'] ?? '') === '' || ($row['amount'] ?? '') === '') {
                    continue;
                }
                $item = OpenItem::query()->where('public_id', $row['open_item'])->where('side', $side)->where('party_id', $party->id)->first()
                    ?? throw FinanceException::make('finance.open_item_invalid', 'That invoice or bill does not belong to this party.', 422, "allocations.{$i}.open_item");
                $allocations[] = ['open_item_id' => $item->id, 'amount' => $row['amount']];
            }

            $result = $post([
                'side' => $side, 'party_id' => $party->id, 'bank_account_id' => $bank->id, 'date' => $data['date'] ?? null ?: now((string) config('core.display_timezone'))->toDateString(),
                'amount' => $data['amount'], 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'allocations' => $allocations,
            ], $request->user()->id, 'web');

            if (is_array($result)) {
                return redirect()->route($this->route($side, 'settlements.index'))->with('status', __('finance::ui.flash.awaiting_approval', ['document' => $result['document_no']]));
            }

            return redirect()->route($this->route($side, 'settlements.show'), $result)->with('status', __('finance::ui.flash.posted', ['document' => $result->document_no]));
        });
    }

    public function show(Request $request, Settlement $settlement): View
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);
        abort_unless($settlement->side === $side, 404);

        return view('finance::subledger.show', [
            'side' => $side, 's' => $this->presenter->one($settlement), 'model' => $settlement, 'canPost' => $request->user()->can(self::POST[$side]), 'canReverse' => $request->user()->can('finance.reverse') && $request->user()->can(self::POST[$side]),
            'openItems' => OpenItem::query()->where('side', $side)->where('party_id', $settlement->party_id)->where('open_amount', '>', 0)->orderBy('due_date')->get(),
        ]);
    }

    public function allocate(Request $request, Settlement $settlement, SettlementService $service): RedirectResponse
    {
        $side = $this->side($request);
        $this->authorize(self::POST[$side]);
        abort_unless($settlement->side === $side, 404);
        $data = $request->validate(['allocations' => ['required', 'array', 'max:200'], 'allocations.*.open_item' => ['nullable', 'uuid'], 'allocations.*.amount' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/']]);

        return $this->attempt(function () use ($service, $settlement, $side, $data) {
            $rows = [];
            foreach (array_values($data['allocations']) as $i => $row) {
                if (($row['open_item'] ?? '') === '' || ($row['amount'] ?? '') === '') {
                    continue;
                }
                $item = OpenItem::query()->where('public_id', $row['open_item'])->where('side', $side)->where('party_id', $settlement->party_id)->first()
                    ?? throw FinanceException::make('finance.open_item_invalid', 'That invoice or bill does not belong to this party.', 422, "allocations.{$i}.open_item");
                $rows[] = ['open_item_id' => $item->id, 'amount' => $row['amount']];
            }
            $service->allocate($settlement, $rows);

            return redirect()->route($this->route($side, 'settlements.show'), $settlement)->with('status', __('finance::ui.flash.saved'));
        });
    }

    public function reverse(Request $request, Settlement $settlement, SettlementService $service): RedirectResponse
    {
        $side = $this->side($request);
        $this->authorize('finance.reverse');
        $this->authorize(self::POST[$side]);
        abort_unless($settlement->side === $side, 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $service, $settlement, $side, $data) {
            $service->reverse($settlement, $data['note'] ?? null, $request->user()->id);

            return redirect()->route($this->route($side, 'settlements.show'), $settlement)->with('status', __('finance::ui.flash.reversed', ['document' => $settlement->document_no]));
        });
    }

    public function ledger(Request $request, string $code): View
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);
        $party = $this->partyOrNull($side, $code) ?? abort(404);
        $to = (string) ($request->query('to') ?: now((string) config('core.display_timezone'))->toDateString());

        return view('finance::subledger.ledger', ['side' => $side, 'ledger' => $this->queries->partyLedger($side, $party->id, $request->query('from') ?: null, $to), 'party' => $party, 'from' => $request->query('from'), 'to' => $to]);
    }

    private function side(Request $request): string
    {
        $side = (string) $request->route('side');
        abort_unless(isset(self::VIEW[$side]), 404);

        return $side;
    }

    private function route(string $side, string $name): string
    {
        return ($side === 'receivable' ? 'finance.receivables.' : 'finance.payables.').$name;
    }

    private function partyOrNull(string $side, string $code): ?PartyInfo
    {
        return $side === OpenItem::RECEIVABLE ? $this->parties->customerByCode($code) : $this->parties->supplierByCode($code);
    }
}
