<?php

namespace App\Modules\Manufacturing\Http\Controllers\Web;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Manufacturing\Models\DowntimeEvent;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionReport;
use App\Modules\Manufacturing\Services\DowntimeService;
use App\Modules\Manufacturing\Services\FormOptions;
use App\Modules\Manufacturing\Services\ManufacturingPresenter;
use App\Modules\Manufacturing\Services\OutputService;
use App\Modules\Manufacturing\Services\ShiftService;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The operator's screens: pick an order, then report the shift's output and any downtime on one page with big controls.
 */
class ReportController extends Controller
{
    use NeedsPermission, ReportsBusinessErrors;

    private const QTY = ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'];

    public function index(Request $request, ManufacturingPresenter $presenter, ShiftService $shifts): View
    {
        $this->need($request, 'manufacturing.output.report');
        $orders = ProductionOrder::query()->whereIn('status', ['released', 'in_progress'])->orderBy('planned_start')->orderBy('id')->get();
        $current = $shifts->current();

        return view('manufacturing::report.index', ['rows' => $presenter->orderHeaders($orders), 'current' => $current === null ? null : ['name' => $current['shift']->name, 'date' => $current['date']->toDateString()]]);
    }

    public function show(Request $request, ProductionOrder $order, ManufacturingPresenter $presenter, FormOptions $options): View
    {
        $this->need($request, 'manufacturing.output.report');
        $detail = $presenter->order($order);
        abort_unless(in_array($detail['status'], ['released', 'in_progress'], true), 409);
        $today = now(config('core.display_timezone'))->toDateString();

        return view('manufacturing::report.show', [
            'order' => $detail, 'options' => $options->operator(app(ShiftService::class)), 'today' => $today,
            'recent' => array_slice(array_reverse(array_values(array_filter($detail['reports'], fn ($r) => ! $r['voided']))), 0, 8),
            'canVoid' => $request->user()->can('manufacturing.output.void'), 'canDowntime' => $request->user()->can('manufacturing.downtime.report'),
        ]);
    }

    public function output(Request $request, ProductionOrder $order, OutputService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.output.report');
        $data = $request->validate([
            'shift' => ['required', 'string', 'max:30'], 'date' => ['nullable', 'date_format:Y-m-d'], 'produced' => self::QTY, 'rejected' => self::QTY, 'rework' => self::QTY,
            'operation' => ['nullable', 'integer'], 'machine' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $service->report($order, $data, $request->user()->id);

            return redirect()->route('manufacturing.report.show', $order)->with('status', __('manufacturing::ui.flash.output_saved'))->withInput($request->only(['shift', 'machine', 'operation']));
        });
    }

    public function downtime(Request $request, ProductionOrder $order, DowntimeService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.downtime.report');
        $data = $request->validate([
            'shift' => ['required', 'string', 'max:30'], 'date' => ['nullable', 'date_format:Y-m-d'], 'machine' => ['required', 'string', 'max:30'], 'reason' => ['required', 'string', 'max:30'],
            'from' => ['required', 'string', 'max:5'], 'minutes' => ['required', 'string', 'max:5'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $service->report($data + ['order' => $order->order_no], $request->user()->id);

            return redirect()->route('manufacturing.report.show', $order)->with('status', __('manufacturing::ui.flash.downtime_saved'));
        });
    }

    public function voidReport(Request $request, ProductionReport $report, OutputService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.output.void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $report, $service, $data) {
            $service->void($report, $data['reason'], $request->user()->id);

            return back()->with('status', __('manufacturing::ui.flash.report_voided'));
        });
    }

    public function voidDowntime(Request $request, DowntimeEvent $event, DowntimeService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.output.void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $event, $service, $data) {
            $service->void($event, $data['reason'], $request->user()->id);

            return back()->with('status', __('manufacturing::ui.flash.downtime_voided'));
        });
    }
}
