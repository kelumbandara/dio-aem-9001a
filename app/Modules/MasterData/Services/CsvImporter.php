<?php

namespace App\Modules\MasterData\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Actions\SaveItem;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\MasterData\Http\Requests\SaveCustomerRequest;
use App\Modules\MasterData\Http\Requests\SaveItemRequest;
use App\Modules\MasterData\Http\Requests\SaveSupplierRequest;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Imports items, customers or suppliers from CSV. Rows are matched by code: a known code updates that
 * record, a blank or new code creates one. A blank cell means "not given" (kept on update, default on create).
 * It goes through the same mapper, validation and Save actions as the API and the forms.
 *
 * All or nothing: if any row has a problem, nothing is saved and every problem is reported. A dry run
 * does the whole job and then rolls back, so the report is exactly what a real run would do.
 */
final class CsvImporter
{
    public const KINDS = ['items', 'customers', 'suppliers'];

    public const MAX_ROWS = 5000;

    private const COLUMNS = [
        'items' => ['code', 'name', 'description', 'category', 'item_type', 'base_uom', 'is_sellable', 'is_purchasable', 'tracking', 'shelf_life_days', 'barcode', 'default_tax_code', 'is_active'],
        'customers' => ['code', 'name', 'legal_name', 'kind', 'tax_id', 'business_reg_no', 'nic', 'email', 'phone', 'website', 'currency', 'payment_terms', 'price_list', 'credit_limit', 'notes', 'is_active'],
        'suppliers' => ['code', 'name', 'legal_name', 'kind', 'tax_id', 'business_reg_no', 'nic', 'email', 'phone', 'website', 'currency', 'payment_terms', 'price_list', 'lead_time_days', 'notes', 'is_active'],
    ];

    public function __construct(
        private readonly SaveItem $items,
        private readonly SaveCustomer $customers,
        private readonly SaveSupplier $suppliers,
        private readonly InputMapper $map,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<string> the header row of a template file */
    public static function columns(string $kind): array
    {
        return self::COLUMNS[$kind] ?? [];
    }

    /**
     * @return array{kind: string, dry_run: bool, rows: int, created: int, updated: int, committed: bool, errors: list<array{row: int, field: ?string, message: string}>}
     */
    public function __invoke(string $kind, string $path, bool $dryRun): array
    {
        $result = ['kind' => $kind, 'dry_run' => $dryRun, 'rows' => 0, 'created' => 0, 'updated' => 0, 'committed' => false, 'errors' => []];

        if (! in_array($kind, self::KINDS, true)) {
            return $this->fail($result, 0, null, "Unknown import kind '{$kind}'.");
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $this->fail($result, 0, null, 'The file could not be read.');
        }

        try {
            $header = $this->header($handle, $kind);
            if (is_string($header)) {
                return $this->fail($result, 1, null, $header);
            }

            // Read everything first so an oversized file is refused before any row is processed.
            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;   // blank line
                }
                $rows[$line] = $cells;
                if (count($rows) > self::MAX_ROWS) {
                    $result['rows'] = count($rows);

                    return $this->fail($result, $line, null, 'Too many rows; the limit is '.self::MAX_ROWS.' per file.');
                }
            }

            DB::beginTransaction();

            try {
                $seen = [];
                foreach ($rows as $line => $cells) {
                    $result['rows']++;
                    $this->row($kind, $header, $cells, $line, $seen, $result);
                }

                $commit = ! $dryRun && $result['errors'] === [] && $result['rows'] > 0;
                if ($commit) {
                    $this->audit->record('masterdata.import', null, [], ['kind' => $kind, 'rows' => $result['rows'], 'created' => $result['created'], 'updated' => $result['updated']]);
                    DB::commit();
                } else {
                    DB::rollBack();
                }
                $result['committed'] = $commit;
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } finally {
            fclose($handle);
        }

        if ($result['rows'] === 0 && $result['errors'] === []) {
            $result['errors'][] = ['row' => 1, 'field' => null, 'message' => 'The file has no data rows.'];
        }

        return $result;
    }

    /**
     * @param  resource  $handle
     * @return list<string>|string header columns, or an error message
     */
    private function header($handle, string $kind): array|string
    {
        $row = fgetcsv($handle, 0, ',', '"', '');
        if ($row === false || $row === [null]) {
            return 'The file is empty.';
        }

        $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $row);

        $unknown = array_values(array_diff($header, self::COLUMNS[$kind]));
        if ($unknown !== []) {
            return 'Unknown column(s): '.implode(', ', $unknown).'. Expected: '.implode(', ', self::COLUMNS[$kind]).'.';
        }
        if (count(array_unique($header)) !== count($header)) {
            return 'A column appears more than once.';
        }
        if (! in_array('name', $header, true)) {
            return "The file needs a 'name' column.";
        }

        return $header;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $cells
     * @param  array<string, int>  $seen
     * @param  array<string, mixed>  $result
     */
    private function row(string $kind, array $header, array $cells, int $line, array &$seen, array &$result): void
    {
        $input = [];
        foreach ($header as $i => $column) {
            $value = trim((string) ($cells[$i] ?? ''));
            if ($value !== '') {
                $input[$column] = $value;
            }
        }

        try {
            DB::transaction(function () use ($kind, $input, $line, &$seen, &$result): void {
                $code = isset($input['code']) ? strtoupper($input['code']) : null;
                if ($code !== null) {
                    if (isset($seen[$code])) {
                        throw ValidationException::withMessages(['code' => "Code {$code} already appears on line {$seen[$code]}."]);
                    }
                    $seen[$code] = $line;
                    $input['code'] = $code;
                }

                $existing = $code !== null ? $this->find($kind, $code) : null;
                $data = $this->validated($kind, $this->booleans($input), $existing !== null);
                $existing ? $this->update($kind, $existing, $data) : $this->create($kind, $data);
                $result[$existing ? 'updated' : 'created']++;
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $result['errors'][] = ['row' => $line, 'field' => $field, 'message' => $message];
                }
            }
        } catch (BusinessException $e) {
            $result['errors'][] = ['row' => $line, 'field' => $e->details[0]['field'] ?? null, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function fail(array $result, int $row, ?string $field, string $message): array
    {
        $result['errors'][] = ['row' => $row, 'field' => $field, 'message' => $message];

        return $result;
    }

    private function find(string $kind, string $code): Item|Customer|Supplier|null
    {
        $model = ['items' => Item::class, 'customers' => Customer::class, 'suppliers' => Supplier::class][$kind];

        return $model::query()->whereRaw('upper(code) = ?', [$code])->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function booleans(array $input): array
    {
        foreach (['is_sellable', 'is_purchasable', 'is_active'] as $flag) {
            if (! isset($input[$flag])) {
                continue;
            }
            $value = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                throw ValidationException::withMessages([$flag => "{$flag} must be yes or no."]);
            }
            $input[$flag] = $value;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(string $kind, array $input, bool $existing): array
    {
        $rules = match ($kind) {
            'items' => (new SaveItemRequest)->rules(),
            'customers' => (new SaveCustomerRequest)->rules(),
            'suppliers' => (new SaveSupplierRequest)->rules(),
            default => throw new \InvalidArgumentException("Unknown import kind '{$kind}'."),
        };

        // The request rules describe API payloads; keep only what a CSV row can carry.
        $rules = array_filter($rules, fn ($key) => ! str_contains($key, '.') && ! in_array($key, ['version', 'uoms', 'contacts', 'addresses'], true), ARRAY_FILTER_USE_KEY);
        if ($existing) {
            $rules = array_map(fn ($r) => array_map(fn ($rule) => $rule === 'required' ? 'sometimes' : $rule, $r), $rules);
        }

        return Validator::make($input, $rules)->validate();
    }

    /** @param  array<string, mixed>  $data */
    private function create(string $kind, array $data): void
    {
        match ($kind) {
            'items' => ($this->items)($this->map->item($data)),
            'customers' => ($this->customers)($this->map->customer($data)),
            'suppliers' => ($this->suppliers)($this->map->supplier($data)),
            default => throw new \InvalidArgumentException("Unknown import kind '{$kind}'."),
        };
    }

    /** @param  array<string, mixed>  $data */
    private function update(string $kind, Item|Customer|Supplier $record, array $data): void
    {
        $data = array_diff_key($data, ['code' => true]);

        match ($kind) {
            'items' => ($this->items)($this->map->item($data, $record->load('uoms')), $record, $record->version),
            'customers' => ($this->customers)($this->map->customer($data, $record), $record, $record->version),
            'suppliers' => ($this->suppliers)($this->map->supplier($data, $record), $record, $record->version),
            default => throw new \InvalidArgumentException("Unknown import kind '{$kind}'."),
        };
    }
}
