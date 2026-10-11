<?php

namespace App\Modules\Finance\Http\Controllers\Api;

use App\Modules\Finance\Actions\PostSettlement;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\SettlementPresenter;
use App\Modules\Finance\Services\SettlementService;
use App\Modules\Finance\Services\SubledgerQueries;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\DTOs\PartyInfo;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Receivables (customers: invoices and receipts) and payables (suppliers: bills and payments); the side comes from the route. */
class SubledgerController extends Controller
{
    private const VIEW = ['receivable' => 'finance.ar.view', 'payable' => 'finance.ap.view'];

    private const POST = ['receivable' => 'finance.ar.receive', 'payable' => 'finance.ap.pay'];

    public function __construct(
        private readonly SubledgerQueries $queries,
        private readonly PartyDirectory $parties,
        private readonly SettlementPresenter $presenter,
    ) {}

    public function openItems(Request $request): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);
        $request->validate(['filter.party' => ['sometimes', 'string', 'max:30'], 'filter.open' => ['sometimes', 'in:true,false,1,0'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $party = $request->input('filter.party') ? $this->party($side, (string) $request->input('filter.party')) : null;

        $page = $this->queries->openItems($side, $party?->id, filter_var($request->input('filter.open', true), FILTER_VALIDATE_BOOLEAN), (int) $request->query('per_page', 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => array_diff_key($r, ['internal_id' => 0]))->values(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function ageing(Request $request): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);

        return response()->json(['data' => $this->queries->ageing($side)]);
    }

    public function tieOut(Request $request): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);

        return response()->json(['data' => $this->queries->tieOut($side)]);
    }

    public function unallocated(Request $request): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);

        return response()->json(['data' => $this->queries->unallocated($side)]);
    }

    public function ledger(Request $request, string $code): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);
        $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']]);
        $party = $this->party($side, $code);

        return response()->json(['data' => $this->queries->partyLedger($side, $party->id, $request->query('from'), (string) $request->query('to', now((string) config('core.display_timezone'))->toDateString()))]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);
        $page = Settlement::query()->where('side', $side)->orderByDesc('settled_on')->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $this->presenter->many($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function showSettlement(Request $request, Settlement $settlement): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::VIEW[$side]);
        abort_unless($settlement->side === $side, 404);

        return response()->json(['data' => $this->presenter->one($settlement)]);
    }

    /** Posts at once, or (payments only, when a policy asks for approval) answers 202 with the request. */
    public function store(Request $request, PostSettlement $post): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::POST[$side]);
        $data = $request->validate([
            'party' => ['required', 'string', 'max:30'], 'bank_account' => ['required', 'uuid'], 'date' => ['nullable', 'date_format:Y-m-d'], 'amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'method' => ['sometimes', 'in:'.implode(',', Settlement::METHODS)], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500'],
            'allocations' => ['sometimes', 'array', 'max:200'], 'allocations.*.open_item' => ['required', 'uuid'], 'allocations.*.amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
        ]);

        $result = $post($this->input($side, $data), $request->user()->id, 'api', $request->header('Idempotency-Key'));

        if (is_array($result)) {
            return response()->json(['data' => $result + ['status' => 'pending']], 202);
        }

        return response()->json(['data' => $this->presenter->one($result)], 201);
    }

    public function allocate(Request $request, Settlement $settlement, SettlementService $service): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize(self::POST[$side]);
        abort_unless($settlement->side === $side, 404);
        $data = $request->validate(['allocations' => ['required', 'array', 'min:1', 'max:200'], 'allocations.*.open_item' => ['required', 'uuid'], 'allocations.*.amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/']]);

        return response()->json(['data' => $this->presenter->one($service->allocate($settlement, $this->allocations($side, $settlement->party_id, $data['allocations'])))]);
    }

    public function reverse(Request $request, Settlement $settlement, SettlementService $service): JsonResponse
    {
        $side = $this->side($request);
        $this->authorize('finance.reverse');
        $this->authorize(self::POST[$side]);
        abort_unless($settlement->side === $side, 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->presenter->one($service->reverse($settlement, $data['note'] ?? null, $request->user()->id))], 201);
    }

    private function side(Request $request): string
    {
        $side = (string) $request->route('side');
        abort_unless(isset(self::VIEW[$side]), 404);

        return $side;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function input(string $side, array $data): array
    {
        $party = $this->party($side, $data['party']);
        $bank = BankAccount::query()->where('public_id', $data['bank_account'])->first() ?? throw FinanceException::make('finance.bank_invalid', 'Unknown bank account.', 422, 'bank_account');

        return [
            'side' => $side, 'party_id' => $party->id, 'bank_account_id' => $bank->id, 'date' => $data['date'] ?? null ?: now((string) config('core.display_timezone'))->toDateString(),
            'amount' => $data['amount'], 'method' => $data['method'] ?? 'bank_transfer', 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
            'allocations' => $this->allocations($side, $party->id, $data['allocations'] ?? []),
        ];
    }

    /**
     * @param  list<array{open_item: string, amount: string}>  $rows
     * @return list<array{open_item_id: int, amount: string}>
     */
    private function allocations(string $side, int $partyId, array $rows): array
    {
        return array_map(function (array $row, int $i) use ($side, $partyId) {
            $item = OpenItem::query()->where('public_id', $row['open_item'])->where('side', $side)->where('party_id', $partyId)->first()
                ?? throw FinanceException::make('finance.open_item_invalid', 'That invoice or bill does not belong to this party.', 422, "allocations.{$i}.open_item");

            return ['open_item_id' => $item->id, 'amount' => $row['amount']];
        }, $rows, array_keys($rows));
    }

    private function party(string $side, string $code): PartyInfo
    {
        $party = $side === OpenItem::RECEIVABLE ? $this->parties->customerByCode($code) : $this->parties->supplierByCode($code);

        return $party ?? throw FinanceException::make('finance.unknown_code', 'Unknown '.($side === OpenItem::RECEIVABLE ? 'customer' : 'supplier').' code.', 422, 'party');
    }
}
