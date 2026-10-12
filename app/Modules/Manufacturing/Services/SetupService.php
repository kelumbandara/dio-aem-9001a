<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Manufacturing\Models\DowntimeReason;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionLine;
use App\Modules\Manufacturing\Models\Shift;
use App\Modules\Manufacturing\Models\WorkCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Work centres, production lines, machines and shifts: small lists the rest of manufacturing points at. Nothing here is
 * deleted (an order or a routing may refer to it); an entry is switched off instead.
 */
final class SetupService
{
    /** entity key => [model, label key] */
    public const ENTITIES = [
        'work-centers' => WorkCenter::class,
        'lines' => ProductionLine::class,
        'machines' => Machine::class,
        'shifts' => Shift::class,
        'downtime-reasons' => DowntimeReason::class,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return class-string<Model> */
    public function model(string $entity): string
    {
        return self::ENTITIES[$entity] ?? abort(404);
    }

    /**
     * Create or change one entry. Validation failures are ordinary Laravel validation errors (web redirects back, the API answers 422).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function save(string $entity, array $data, ?Model $existing = null): Model
    {
        $model = $this->model($entity);
        foreach (['work_center', 'production_line', 'code'] as $k) {
            if (isset($data[$k]) && is_string($data[$k])) {
                $data[$k] = strtoupper(trim($data[$k]));
            }
        }
        $clean = Validator::make($data, $this->rules($entity, $existing))->validate();
        $values = $this->values($entity, $clean, $existing);

        return DB::transaction(function () use ($model, $entity, $values, $existing): Model {
            if ($existing === null) {
                $row = $model::query()->create($values);
            } else {
                $row = $existing;
                $row->forceFill($values)->save();
            }
            $this->audit->record("manufacturing.{$entity}_saved", $row, [], ['code' => $row->getAttribute('code')]);

            return $row->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function rules(string $entity, ?Model $existing): array
    {
        $table = (new (self::ENTITIES[$entity]))->getTable();
        $code = ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-]*$/', Rule::unique($table, 'code')->ignore($existing?->getKey())];
        $base = ['code' => $existing === null ? $code : ['sometimes', ...$code], 'name' => ['required', 'string', 'max:150']];

        return match ($entity) {
            'work-centers' => $base + [
                'description' => ['nullable', 'string', 'max:2000'],
                'capacity_hours_per_day' => ['required', 'numeric', 'gt:0', 'max:24', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
                'hourly_rate' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'lines' => $base + ['description' => ['nullable', 'string', 'max:2000'], 'is_active' => ['sometimes', 'boolean']],
            'machines' => $base + [
                'work_center' => ['required', 'string', 'exists:work_centers,code'],
                'production_line' => ['nullable', 'string', 'exists:production_lines,code'],
                'status' => ['required', Rule::in(Machine::STATUSES)],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'shifts' => $base + [
                'start_time' => ['required', 'date_format:H:i'],
                'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
                'days' => ['required', 'regex:/^[01]{7}$/'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'downtime-reasons' => $base + ['category' => ['required', Rule::in(DowntimeReason::CATEGORIES)], 'is_active' => ['sometimes', 'boolean']],
            default => abort(404),
        };
    }

    /**
     * @param  array<string, mixed>  $clean
     * @return array<string, mixed>
     */
    private function values(string $entity, array $clean, ?Model $existing): array
    {
        $values = $clean;
        if (isset($values['code'])) {
            $values['code'] = strtoupper($values['code']);
        }
        if (array_key_exists('is_active', $values)) {
            $values['is_active'] = (bool) $values['is_active'];
        } elseif (in_array($entity, ['work-centers', 'lines', 'shifts', 'downtime-reasons'], true) && $existing === null) {
            $values['is_active'] = true;
        }
        if ($entity === 'machines') {
            $values['work_center_id'] = WorkCenter::query()->where('code', $values['work_center'])->value('id');
            $values['production_line_id'] = ($values['production_line'] ?? '') === '' ? null : ProductionLine::query()->where('code', $values['production_line'])->value('id');
            unset($values['work_center'], $values['production_line']);
        }

        return $values;
    }
}
