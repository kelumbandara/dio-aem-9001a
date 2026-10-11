<?php

namespace App\Modules\MasterData\Http\Controllers\Web;

use App\Modules\MasterData\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Support\Http\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Screens shared by customers and suppliers; the subclasses say which one. */
abstract class PartyController extends Controller
{
    use ReportsBusinessErrors;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Route name prefix and view folder, e.g. "customers". */
    abstract protected function slug(): string;

    /** Price list type offered on the form. */
    abstract protected function priceListType(): string;

    /** @return list<string> relations to load for the show/edit screens */
    protected function relations(): array
    {
        return ['contacts', 'addresses', 'currency', 'paymentTerms', 'priceList'];
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', $this->model());
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status', 'active');

        $rows = ($this->model())::query()
            ->when($q !== '', function ($query) use ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
                $query->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(tax_id, \'\')) like ?', [$term])->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$term]));
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('code')->paginate(25)->withQueryString();

        return view('masterdata::parties.index', ['rows' => $rows, 'q' => $q, 'status' => $status] + $this->context());
    }

    public function create(): View
    {
        $this->authorize('create', $this->model());

        return view('masterdata::parties.form', ['party' => null] + $this->choices() + $this->context());
    }

    protected function renderShow(Model $party): View
    {
        $this->authorize('view', $party);

        return view('masterdata::parties.show', ['party' => $party->load($this->relations())] + $this->context() + $this->showExtras($party));
    }

    protected function renderEdit(Model $party): View
    {
        $this->authorize('update', $party);

        return view('masterdata::parties.form', ['party' => $party->load($this->relations())] + $this->choices() + $this->context());
    }

    /** @return array<string, mixed> */
    protected function showExtras(Model $party): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function context(): array
    {
        return ['slug' => $this->slug(), 'modelClass' => $this->model()];
    }

    /** @return array<string, mixed> */
    private function choices(): array
    {
        return [
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'terms' => PaymentTerm::query()->where('is_active', true)->orderBy('days')->get(['code', 'name']),
            'priceLists' => PriceList::query()->where('is_active', true)->where('type', $this->priceListType())->orderBy('code')->get(['code', 'name']),
        ];
    }

    /**
     * Blank child rows are dropped; the rest replace the lists.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalise(array $data): array
    {
        foreach (['contacts' => ['name', 'email', 'phone', 'job_title'], 'addresses' => ['line1', 'line2', 'city', 'district', 'postal_code']] as $list => $keys) {
            if (isset($data[$list])) {
                $data[$list] = array_values(array_filter($data[$list], fn ($row) => collect($keys)->contains(fn ($k) => ($row[$k] ?? '') !== '')));
            }
        }

        return $data;
    }

    protected function saved(Model $party): RedirectResponse
    {
        return redirect()->route("{$this->slug()}.show", $party)->with('status', __('masterdata::ui.flash.saved'));
    }
}
