<?php

namespace App\Modules\MasterData\Http\Controllers\Web;

use App\Modules\MasterData\Actions\Reference\AddTaxRate;
use App\Modules\MasterData\Actions\Reference\RemoveTaxRate;
use App\Modules\MasterData\Actions\Reference\SaveExchangeRate;
use App\Modules\MasterData\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\TaxRate;
use App\Modules\MasterData\Reference\ReferenceRegistry;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/** One set of screens for units, categories, currencies, tax codes and payment terms (see ReferenceDefinition). */
class ReferenceController extends Controller
{
    use ReportsBusinessErrors;

    public function __construct(private readonly ReferenceRegistry $registry) {}

    public function index(Request $request, string $key = 'units-of-measure'): View
    {
        $definition = $this->registry->get($key);
        $this->authorize('viewAny', $definition->modelClass());

        $editing = $request->query('edit') ? $definition->find((string) $request->query('edit')) : null;
        if ($editing) {
            $this->authorize('update', $editing);
        }

        return view('masterdata::reference.index', [
            'registry' => $this->registry, 'definition' => $definition, 'key' => $key,
            'rows' => $definition->query()->get()->map(fn ($m) => ['model' => $m, 'data' => $definition->present($m)]),
            'editing' => $editing,
            'canManage' => $request->user()->can('create', $definition->modelClass()),
            'detail' => $this->detail($key, $request),
        ]);
    }

    public function store(Request $request, string $key): RedirectResponse
    {
        $definition = $this->registry->get($key);
        $this->authorize('create', $definition->modelClass());

        return $this->attempt(function () use ($request, $definition, $key) {
            $data = Validator::make($this->withFlags($request->all(), $definition->fields()), $definition->rules(null))->validate();
            $definition->save($data, null);

            return redirect()->route('masterdata.reference.index', $key)->with('status', __('masterdata::ui.flash.saved'));
        });
    }

    public function update(Request $request, string $key, string $id): RedirectResponse
    {
        $definition = $this->registry->get($key);
        $model = $definition->find($id);
        $this->authorize('update', $model);

        return $this->attempt(function () use ($request, $definition, $model, $key) {
            $current = collect($model->only(array_keys($definition->fields())))->except('parent', 'code')->all();
            $input = [...$current, ...$this->withFlags($request->except('code'), $definition->fields())];
            $data = Validator::make($input, $definition->rules($model))->validate();
            $definition->save([...$current, ...$data], $model);

            return redirect()->route('masterdata.reference.index', $key)->with('status', __('masterdata::ui.flash.saved'));
        });
    }

    public function setExchangeRate(Request $request, Currency $currency, SaveExchangeRate $save): RedirectResponse
    {
        $this->authorize('update', $currency);
        $data = $request->validate([
            'effective_on' => ['required', 'date'],
            'rate' => ['required', 'regex:/^\d{1,10}(\.\d{1,8})?$/'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        return $this->attempt(function () use ($save, $currency, $data) {
            $save($currency, $data['effective_on'], $data['rate'], $data['source'] ?? null);

            return redirect()->route('masterdata.reference.index', ['key' => 'currencies', 'detail' => $currency->code])->with('status', __('masterdata::ui.flash.saved'));
        });
    }

    public function addTaxRate(Request $request, TaxCode $taxCode, AddTaxRate $add): RedirectResponse
    {
        $this->authorize('update', $taxCode);
        $data = $request->validate([
            'rate' => ['required', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        return $this->attempt(function () use ($add, $taxCode, $data) {
            $add($taxCode, $data['rate'], $data['effective_from'], $data['effective_to'] ?? null);

            return redirect()->route('masterdata.reference.index', ['key' => 'tax-codes', 'detail' => $taxCode->code])->with('status', __('masterdata::ui.flash.saved'));
        });
    }

    public function removeTaxRate(TaxCode $taxCode, TaxRate $rate, RemoveTaxRate $remove): RedirectResponse
    {
        $this->authorize('update', $taxCode);
        abort_unless($rate->tax_code_id === $taxCode->id, 404);

        return $this->attempt(function () use ($remove, $rate, $taxCode) {
            $remove($rate);

            return redirect()->route('masterdata.reference.index', ['key' => 'tax-codes', 'detail' => $taxCode->code])->with('status', __('masterdata::ui.flash.saved'));
        });
    }

    /**
     * Unchecked checkboxes are not sent by browsers; read them as "off" so they can be switched off.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function withFlags(array $input, array $fields): array
    {
        foreach ($fields as $name => $field) {
            if (($field['type'] ?? '') === 'checkbox') {
                $input[$name] = filter_var($input[$name] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $input;
    }

    /**
     * History shown under the list for the selected currency or tax code.
     *
     * @return array<string, mixed>|null
     */
    private function detail(string $key, Request $request): ?array
    {
        $code = (string) $request->query('detail', '');
        if ($code === '') {
            return null;
        }

        if ($key === 'currencies') {
            $currency = Currency::query()->where('code', strtoupper($code))->first();

            return $currency ? ['type' => 'currency', 'model' => $currency, 'rates' => $currency->rates()->limit(60)->get()] : null;
        }
        if ($key === 'tax-codes') {
            $tax = TaxCode::query()->with('rates')->where('code', strtoupper($code))->first();

            return $tax ? ['type' => 'tax', 'model' => $tax, 'rates' => $tax->rates] : null;
        }

        return null;
    }
}
