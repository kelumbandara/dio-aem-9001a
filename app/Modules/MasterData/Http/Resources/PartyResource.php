<?php

namespace App\Modules\MasterData\Http\Resources;

use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer or supplier. NIC is shown only to people who may manage the record (it is personal data);
 * bank accounts are handled by SupplierResource.
 *
 * @mixin Customer|Supplier
 */
class PartyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canSeePersonal = $request->user()?->can('update', $this->resource) ?? false;

        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'kind' => $this->kind,
            'tax_id' => $this->tax_id,
            'business_reg_no' => $this->business_reg_no,
            'nic' => $canSeePersonal ? $this->nic : null,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'currency' => $this->currency?->code,
            'payment_terms' => $this->paymentTerms?->code,
            'price_list' => $this->price_list_id ? $this->priceListCode() : null,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'version' => $this->version,
            'contacts' => $this->contacts->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'job_title' => $c->job_title, 'email' => $c->email, 'phone' => $c->phone, 'is_primary' => $c->is_primary,
            ])->values(),
            'addresses' => $this->addresses->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'line1' => $a->line1, 'line2' => $a->line2, 'city' => $a->city,
                'district' => $a->district, 'postal_code' => $a->postal_code, 'country' => $a->country, 'is_default' => $a->is_default,
            ])->values(),
            ...$this->extra($request),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function extra(Request $request): array
    {
        $party = $this->resource;

        return $party instanceof Customer
            ? ['credit_limit' => $party->credit_limit]
            : ($party instanceof Supplier ? ['lead_time_days' => $party->lead_time_days] : []);
    }

    private function priceListCode(): ?string
    {
        return PriceList::query()->whereKey($this->price_list_id)->value('code');
    }
}
