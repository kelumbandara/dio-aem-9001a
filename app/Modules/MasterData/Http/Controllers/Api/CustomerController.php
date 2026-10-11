<?php

namespace App\Modules\MasterData\Http\Controllers\Api;

use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Http\Requests\SaveCustomerRequest;
use App\Modules\MasterData\Http\Resources\PartyResource;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    private const WITH = ['contacts', 'addresses', 'currency', 'paymentTerms'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

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

        $customers = Customer::query()->with(self::WITH)
            ->when(! empty($filter['q']), function ($q) use ($filter) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($filter['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(tax_id, \'\')) like ?', [$term])->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$term]));
            })
            ->when(isset($filter['is_active']), fn ($q) => $q->where('is_active', filter_var($filter['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy($column, $direction)->orderBy('id')
            ->paginate((int) $request->query('per_page', 25))->withQueryString();

        return PartyResource::collection($customers);
    }

    public function show(Customer $customer): PartyResource
    {
        $this->authorize('view', $customer);

        return new PartyResource($customer->load(self::WITH));
    }

    public function store(SaveCustomerRequest $request, SaveCustomer $save, InputMapper $map): JsonResponse
    {
        $customer = $save($map->customer($request->validated()));

        return (new PartyResource($customer->load(self::WITH)))->response()->setStatusCode(201)
            ->header('Location', route('api.v1.customers.show', $customer));
    }

    public function update(SaveCustomerRequest $request, Customer $customer, SaveCustomer $save, InputMapper $map): PartyResource
    {
        $validated = $request->validated();
        $updated = $save($map->customer($validated, $customer), $customer, (int) $validated['version']);

        return new PartyResource($updated->load(self::WITH));
    }
}
