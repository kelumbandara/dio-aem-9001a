<?php

namespace App\Modules\Manufacturing\Http\Controllers\Web;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Models\Shift;
use App\Modules\Manufacturing\Services\ShiftReport;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Output and downtime by shift. */
class ShiftReportController extends Controller
{
    use NeedsPermission;

    public function index(Request $request, ShiftReport $report, ItemCatalog $items): View|RedirectResponse
    {
        $this->need($request, 'manufacturing.view');
        $tz = config('core.display_timezone');
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'shift' => ['nullable', 'string', 'max:30'], 'day' => ['nullable', 'date_format:Y-m-d']]);
        $to = $data['to'] ?? CarbonImmutable::now($tz)->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to, $tz)->subDays(6)->toDateString();
        if ($from > $to || CarbonImmutable::parse($from, $tz)->diffInDays(CarbonImmutable::parse($to, $tz)) > 92) {
            return redirect()->route('manufacturing.shifts.report')->withErrors(['from' => __('manufacturing::ui.shifts.range')]);
        }
        $shift = ! empty($data['shift']) ? Shift::query()->whereRaw('lower(code) = ?', [strtolower($data['shift'])])->first() : null;

        $detail = null;
        if (! empty($data['day']) && $shift !== null) {
            $detail = $report->entries($data['day'], $shift->id);
            $names = $items->findMany(array_values(array_unique(array_filter(array_column($detail['reports'], 'item_id')))));
            $detail['reports'] = array_map(fn (array $r) => $r + ['item' => $names[$r['item_id']]->code ?? ''], $detail['reports']);
        }

        return view('manufacturing::shifts.report', [
            'rows' => $report->summary($from, $to, $shift?->id), 'from' => $from, 'to' => $to, 'shift' => $shift?->code,
            'shifts' => Shift::query()->orderBy('start_time')->get(['code', 'name']), 'detail' => $detail, 'day' => $data['day'] ?? null,
        ]);
    }
}
