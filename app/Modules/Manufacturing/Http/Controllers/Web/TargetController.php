<?php

namespace App\Modules\Manufacturing\Http\Controllers\Web;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Models\Shift;
use App\Modules\Manufacturing\Models\ShiftTarget;
use App\Modules\Manufacturing\Services\ShiftTargetService;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What each shift is expected to make of each product. */
class TargetController extends Controller
{
    use NeedsPermission;

    public function index(Request $request, ItemCatalog $items): View
    {
        $this->need($request, 'manufacturing.view');
        $targets = ShiftTarget::query()->orderBy('shift_id')->orderBy('item_id')->get();
        $infos = $items->findMany($targets->pluck('item_id')->all());
        $shifts = Shift::query()->get()->keyBy('id');

        return view('manufacturing::targets.index', [
            'rows' => $targets->map(fn (ShiftTarget $t) => [
                'shift' => $shifts[$t->shift_id]->code ?? '', 'shift_name' => $shifts[$t->shift_id]->name ?? '', 'item' => $infos[$t->item_id]->code ?? '', 'item_name' => $infos[$t->item_id]->name ?? '',
                'uom' => $infos[$t->item_id]->baseUomCode ?? '', 'target' => Decimal::trim((string) $t->target_qty), 'active' => $t->is_active,
            ])->all(),
            'shifts' => Shift::query()->where('is_active', true)->orderBy('start_time')->get(['code', 'name']),
            'products' => collect($items->search('', 500))->filter(fn ($i) => $i->isActive && $i->isStocked())->map(fn ($i) => ['code' => $i->code, 'name' => $i->name, 'uom' => $i->baseUomCode])->values()->all(),
            'canManage' => $request->user()->can('manufacturing.setup.manage'),
        ]);
    }

    public function save(Request $request, ShiftTargetService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.setup.manage');
        $data = $request->validate(['shift' => ['required', 'string', 'max:30'], 'item' => ['required', 'string', 'max:60'], 'target' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/']]);
        $data['is_active'] = $request->boolean('is_active', true);

        try {
            $service->save($data);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('manufacturing.targets.index')->with('status', __('manufacturing::ui.flash.setup_saved'));
    }
}
