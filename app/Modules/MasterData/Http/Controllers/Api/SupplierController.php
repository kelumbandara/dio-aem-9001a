<?php

namespace App\Modules\MasterData\Http\Controllers\Api;

use App\Modules\MasterData\Actions\RequestSupplierBankAccountChange;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\MasterData\Http\Requests\BankAccountRequest;
use App\Modules\MasterData\Http\Requests\SaveSupplierRequest;
use App\Modules\MasterData\Http\Resources\SupplierResource;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierBankAccount;
use App\Modules\MasterData\Support\CodeLookup;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    private const WITH = ['contacts', 'addresses', 'bankAccounts', 'currency', 'paymentTerms'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Supplier::class);

        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'max:30'],
            'filter.q' => ['sometimes', 'string', 'max:100'],
            'filter.is_active' => ['sometimes', 'in:true,false,1,0'],
        ]);

        $filter = (array) $request->input('filter', []);
        $sort = (string) $request->query('sort', 'code');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = in_array(ltrim($sort, '-'), ['code', 'name', 'created_at'], true) ? ltrim($sort, '-') : 'code';

        $suppliers = Supplier::query()->with(self::WITH)
            ->when(! empty($filter['q']), function ($q) use ($filter) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($filter['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(tax_id, \'\')) like ?', [$term]));
            })
            ->when(isset($filter['is_active']), fn ($q) => $q->where('is_active', filter_var($filter['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy($column, $direction)->orderBy('id')
            ->paginate((int) $request->query('per_page', 25))->withQueryString();

        return SupplierResource::collection($suppliers);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        $this->authorize('view', $supplier);

        return new SupplierResource($supplier->load(self::WITH));
    }

    public function store(SaveSupplierRequest $request, SaveSupplier $save, InputMapper $map): JsonResponse
    {
        $supplier = $save($map->supplier($request->validated()));

        return (new SupplierResource($supplier->load(self::WITH)))->response()->setStatusCode(201)
            ->header('Location', route('api.v1.suppliers.show', $supplier));
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier, SaveSupplier $save, InputMapper $map): SupplierResource
    {
        $validated = $request->validated();
        $updated = $save($map->supplier($validated, $supplier), $supplier, (int) $validated['version']);

        return new SupplierResource($updated->load(self::WITH));
    }

    public function addBankAccount(BankAccountRequest $request, Supplier $supplier, RequestSupplierBankAccountChange $change, CodeLookup $codes): JsonResponse
    {
        return $this->bankChange($request, $supplier, $change, $codes, 'create');
    }

    public function updateBankAccount(BankAccountRequest $request, Supplier $supplier, SupplierBankAccount $account, RequestSupplierBankAccountChange $change, CodeLookup $codes): JsonResponse
    {
        return $this->bankChange($request, $supplier, $change, $codes, 'update', $account);
    }

    public function deactivateBankAccount(BankAccountRequest $request, Supplier $supplier, SupplierBankAccount $account, RequestSupplierBankAccountChange $change, CodeLookup $codes): JsonResponse
    {
        return $this->bankChange($request, $supplier, $change, $codes, 'deactivate', $account);
    }

    private function bankChange(BankAccountRequest $request, Supplier $supplier, RequestSupplierBankAccountChange $change, CodeLookup $codes, string $op, ?SupplierBankAccount $account = null): JsonResponse
    {
        // Bind the account to this supplier: an id belonging to someone else is simply not found.
        abort_if($account !== null && $account->supplier_id !== $supplier->id, 404);

        $in = $request->validated();
        $outcome = $change([
            'op' => $op,
            'supplier_id' => $supplier->id,
            'account_id' => $account?->id,
            ...$in,
            'currency_id' => $codes->currency($in['currency'] ?? null),
        ], $request->user()->id, 'api', $request->header('Idempotency-Key'));

        if (! $outcome->applied) {
            return response()->json(['data' => ['approval_request_id' => $outcome->approvalRequestId, 'document_no' => $outcome->documentNo, 'status' => 'pending']], 202);
        }

        return response()->json(['data' => (new SupplierResource($supplier->refresh()->load(self::WITH)))->resolve($request)], $op === 'create' ? 201 : 200);
    }
}
