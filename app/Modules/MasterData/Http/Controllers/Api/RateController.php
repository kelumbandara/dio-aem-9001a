<?php

namespace App\Modules\MasterData\Http\Controllers\Api;

use App\Modules\MasterData\Actions\Reference\AddTaxRate;
use App\Modules\MasterData\Actions\Reference\RemoveTaxRate;
use App\Modules\MasterData\Actions\Reference\SaveExchangeRate;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\ExchangeRate;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\TaxRate;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Exchange rates (per currency) and tax rates (per tax code): effective-dated history. */
class RateController extends Controller
{
    public function exchangeRates(Currency $currency): JsonResponse
    {
        $this->authorize('view', $currency);

        return response()->json(['data' => $currency->rates()->limit(200)->get()->map(fn ($r) => $this->exchange($r))->values()]);
    }

    public function setExchangeRate(Request $request, Currency $currency, SaveExchangeRate $save): JsonResponse
    {
        $this->authorize('update', $currency);
        $data = $request->validate([
            'effective_on' => ['required', 'date'],
            'rate' => ['required', 'regex:/^\d{1,10}(\.\d{1,8})?$/'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        return response()->json(['data' => $this->exchange($save($currency, $data['effective_on'], $data['rate'], $data['source'] ?? null))], 201);
    }

    public function addTaxRate(Request $request, TaxCode $taxCode, AddTaxRate $add): JsonResponse
    {
        $this->authorize('update', $taxCode);
        $data = $request->validate([
            'rate' => ['required', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        return response()->json(['data' => $this->tax($add($taxCode, $data['rate'], $data['effective_from'], $data['effective_to'] ?? null))], 201);
    }

    public function removeTaxRate(TaxCode $taxCode, TaxRate $rate, RemoveTaxRate $remove): JsonResponse
    {
        $this->authorize('update', $taxCode);
        abort_unless($rate->tax_code_id === $taxCode->id, 404);
        $remove($rate);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function exchange(ExchangeRate $r): array
    {
        return ['id' => $r->id, 'effective_on' => $r->effective_on->toDateString(), 'rate' => $r->rate, 'source' => $r->source];
    }

    /** @return array<string, mixed> */
    private function tax(TaxRate $r): array
    {
        return ['id' => $r->id, 'rate' => $r->rate, 'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString()];
    }
}
