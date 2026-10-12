<?php

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Models\DowntimeEvent;
use App\Modules\Manufacturing\Models\ProductionReport;
use App\Modules\Manufacturing\Models\Shift;
use App\Modules\Manufacturing\Services\DowntimeService;
use App\Modules\Manufacturing\Services\OutputService;
use App\Modules\Manufacturing\Services\ShiftReport;
use App\Support\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Downtime entries, cancelling output or downtime entries, and the shift-wise report. */
class ShopfloorController extends Controller
{
    use NeedsPermission;

    public function downtime(Request $request, DowntimeService $service): JsonResponse
    {
        $this->need($request, 'manufacturing.downtime.report');
        $data = $request->validate([
            'shift' => ['required', 'string', 'max:30'], 'machine' => ['required', 'string', 'max:30'], 'reason' => ['required', 'string', 'max:30'], 'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'string', 'max:5'], 'minutes' => ['nullable', 'string', 'max:5'], 'started_at' => ['nullable', 'string', 'max:40'], 'ended_at' => ['nullable', 'string', 'max:40'],
            'order' => ['nullable', 'string', 'max:40'], 'notes' => ['nullable', 'string', 'max:500'], 'external_id' => ['nullable', 'string', 'max:100'],
        ]);
        $event = $service->report($data, $request->user()->id, 'manual', $data['external_id'] ?? null);

        return response()->json(['data' => $this->row($event)], 201);
    }

    public function voidDowntime(Request $request, DowntimeEvent $event, DowntimeService $service): JsonResponse
    {
        $this->need($request, 'manufacturing.output.void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->row($service->void($event, $data['reason'], $request->user()->id))]);
    }

    public function voidReport(Request $request, ProductionReport $report, OutputService $service): JsonResponse
    {
        $this->need($request, 'manufacturing.output.void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $done = $service->void($report, $data['reason'], $request->user()->id);

        return response()->json(['data' => ['id' => $done->public_id, 'kind' => $done->kind, 'voided' => $done->voided_at !== null, 'void_reason' => $done->void_reason]]);
    }

    /** Output and downtime by shift for a date range (default: the last 7 days); `shift` narrows it to one shift. */
    public function shiftReport(Request $request, ShiftReport $report): JsonResponse
    {
        $this->need($request, 'manufacturing.view');
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'shift' => ['nullable', 'string', 'max:30']]);
        $tz = config('core.display_timezone');
        $to = $data['to'] ?? CarbonImmutable::now($tz)->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($to, $tz)->subDays(6)->toDateString();
        $shift = ! empty($data['shift']) ? Shift::query()->whereRaw('lower(code) = ?', [strtolower($data['shift'])])->first() : null;

        return response()->json(['data' => $report->summary($from, $to, $shift?->id), 'meta' => ['from' => $from, 'to' => $to, 'shift' => $shift?->code]]);
    }

    /** @return array<string, mixed> */
    private function row(DowntimeEvent $e): array
    {
        return [
            'id' => $e->public_id, 'shift_date' => $e->shift_date->toDateString(), 'minutes' => $e->minutes, 'started_at' => $e->started_at->toIso8601String(), 'ended_at' => $e->ended_at->toIso8601String(),
            'voided' => $e->voided_at !== null, 'void_reason' => $e->void_reason,
        ];
    }
}
