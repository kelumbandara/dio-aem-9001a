<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Plan price, VAT and the vendor's own details, edited on the Settings screen. Config holds only the starting values. */
final class Settings
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function get(string $key): string
    {
        return $this->all()[$key] ?? '';
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = DB::table('settings')->pluck('value', 'key')->map(fn ($v) => (string) $v)->all();
            $this->cache = array_merge(config('billing.defaults'), $stored);
        }

        return $this->cache;
    }

    /** @param  array<string, string|null>  $values */
    public function save(array $values): void
    {
        $known = array_keys(config('billing.defaults'));
        foreach ($values as $key => $value) {
            if (! in_array($key, $known, true)) {
                continue;
            }
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->cache = null;
    }

    /**
     * What the invoice shows as "from": the vendor's details at the moment of issue.
     *
     * @return array<string, string>
     */
    public function issuer(): array
    {
        return [
            'name' => $this->get('issuer_name'), 'address' => $this->get('issuer_address'), 'vat_number' => $this->get('issuer_vat_number'),
            'email' => $this->get('issuer_email'), 'phone' => $this->get('issuer_phone'), 'bank_details' => $this->get('bank_details'),
            'footer' => $this->get('invoice_footer'),
        ];
    }
}
