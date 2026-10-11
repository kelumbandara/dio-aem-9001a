<?php

use App\Modules\MasterData\Actions\Reference\AddTaxRate;
use App\Modules\MasterData\Actions\Reference\SaveExchangeRate;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\MasterData\Contracts\CurrencyConverter;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\Contracts\PriceResolver;
use App\Modules\MasterData\Contracts\TaxCalculator;
use App\Modules\MasterData\Contracts\UomConverter;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\UnitOfMeasure;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;

function failsWith(string $code, Closure $fn): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected {$code}");
}

describe('unit conversion', function () {
    beforeEach(function () {
        // base unit grams (2 decimals); kilograms = 1000 g; box = 12 g (silly, but exercises rounding)
        $this->item = mdItem(['base_uom' => 'G', 'uoms' => [['uom' => 'KG', 'factor' => '1000'], ['uom' => 'BOX', 'factor' => '12']]]);
        $this->uom = fn (string $code) => UnitOfMeasure::where('code', $code)->value('id');
        $this->converter = app(UomConverter::class);
    });

    it('converts to and from the base unit and rounds to the target unit\'s decimals', function () {
        expect($this->converter->convert('1.5', $this->item->id, ($this->uom)('KG'), ($this->uom)('G')))->toBe('1500.00')
            ->and($this->converter->convert('2500', $this->item->id, ($this->uom)('G'), ($this->uom)('KG')))->toBe('2.500')
            ->and($this->converter->convert('1', $this->item->id, ($this->uom)('G'), ($this->uom)('KG')))->toBe('0.001')
            ->and($this->converter->convert('5', $this->item->id, ($this->uom)('G'), ($this->uom)('BOX')))->toBe('0')       // 0.4166 box, boxes have 0 decimals
            ->and($this->converter->convert('6', $this->item->id, ($this->uom)('G'), ($this->uom)('BOX')))->toBe('1')        // 0.5 rounds up
            ->and($this->converter->convert('3', $this->item->id, ($this->uom)('KG'), ($this->uom)('BOX')))->toBe('250');
    });

    it('is exact for the same unit and gives base quantities', function () {
        expect($this->converter->convert('7.125', $this->item->id, ($this->uom)('G'), ($this->uom)('G')))->toBe('7.13')
            ->and($this->converter->toBase('2', $this->item->id, ($this->uom)('KG')))->toBe('2000.00')
            ->and($this->converter->factor($this->item->id, ($this->uom)('G')))->toBe('1')
            ->and($this->converter->factor($this->item->id, ($this->uom)('KG')))->toBe('1000.000000');
    });

    it('refuses units the item is not defined in', function () {
        failsWith('masterdata.uom_not_convertible', fn () => $this->converter->convert('1', $this->item->id, ($this->uom)('L'), ($this->uom)('G')));
        failsWith('masterdata.uom_not_convertible', fn () => $this->converter->factor($this->item->id, ($this->uom)('M')));
    });
});

describe('currency conversion', function () {
    beforeEach(function () {
        $this->fx = app(CurrencyConverter::class);
        $usd = Currency::where('code', 'USD')->first();
        $set = app(SaveExchangeRate::class);
        $set($usd, '2026-01-01', '300.00000000');
        $set($usd, '2026-06-01', '310.50000000');
        $set(Currency::where('code', 'EUR')->first(), '2026-01-01', '330.00000000');
    });

    it('knows the base currency and its decimals', function () {
        expect($this->fx->baseCode())->toBe('LKR')->and($this->fx->decimals('lkr'))->toBe(2)->and($this->fx->rate('LKR'))->toBe('1.00000000');
    });

    it('uses the latest rate on or before the date', function () {
        $on = fn (string $d) => CarbonImmutable::parse($d, 'Asia/Colombo');

        expect($this->fx->rate('USD', $on('2026-05-31')))->toBe('300.00000000')
            ->and($this->fx->rate('USD', $on('2026-06-01')))->toBe('310.50000000')
            ->and($this->fx->rate('USD', $on('2027-01-01')))->toBe('310.50000000');
        failsWith('masterdata.no_exchange_rate', fn () => $this->fx->rate('USD', $on('2025-12-31')));
    });

    it('converts through the base currency and rounds to the target currency', function () {
        $on = CarbonImmutable::parse('2026-06-15', 'Asia/Colombo');

        expect($this->fx->convert('100', 'USD', 'LKR', $on))->toBe('31050.00')
            ->and($this->fx->convert('31050', 'LKR', 'USD', $on))->toBe('100.00')
            ->and($this->fx->convert('100', 'USD', 'EUR', $on))->toBe('94.09')      // 100 × 310.5 / 330 = 94.0909…
            ->and($this->fx->convert('12.345', 'LKR', 'LKR', $on))->toBe('12.35');
    });

    it('treats the business date in Colombo time, not UTC', function () {
        // 2026-05-31 20:00 UTC is already 1 June 01:30 in Sri Lanka, so the June rate applies
        expect($this->fx->rate('USD', CarbonImmutable::parse('2026-05-31 20:00', 'UTC')))->toBe('310.50000000');
    });

    it('refuses unknown currencies and rates for the base currency', function () {
        failsWith('masterdata.currency_not_found', fn () => $this->fx->rate('XXX'));
        failsWith('masterdata.base_currency_locked', fn () => app(SaveExchangeRate::class)(Currency::where('code', 'LKR')->first(), '2026-01-01', '2'));
        failsWith('masterdata.rate_invalid', fn () => app(SaveExchangeRate::class)(Currency::where('code', 'USD')->first(), '2026-01-01', '0'));
        failsWith('masterdata.rate_invalid', fn () => app(SaveExchangeRate::class)(Currency::where('code', 'USD')->first(), '2026-01-01', '3e2'));
    });

    it('replaces the rate for the same date instead of duplicating it', function () {
        $usd = Currency::where('code', 'USD')->first();
        app(SaveExchangeRate::class)($usd, '2026-06-01', '312.00000000');

        expect($usd->rates()->where('effective_on', '2026-06-01')->count())->toBe(1)
            ->and($this->fx->rate('USD', CarbonImmutable::parse('2026-06-02', 'Asia/Colombo')))->toBe('312.00000000');
    });
});

describe('tax calculation', function () {
    beforeEach(function () {
        $this->tax = app(TaxCalculator::class);
        $this->vat = TaxCode::where('code', 'VAT')->value('id');
        $this->on = CarbonImmutable::parse('2026-03-15', 'Asia/Colombo');
    });

    it('seeds VAT at 18% from 1 January 2024 and an exempt code at 0%', function () {
        expect($this->tax->rate($this->vat, $this->on))->toBe('18.0000')
            ->and($this->tax->rate(TaxCode::where('code', 'EXEMPT')->value('id'), $this->on))->toBe('0.0000');
        failsWith('masterdata.no_tax_rate', fn () => $this->tax->rate($this->vat, CarbonImmutable::parse('2023-12-31', 'Asia/Colombo')));
    });

    it('seeds NBT and SSCL inactive and without a rate, so nobody charges them by accident', function () {
        foreach (['NBT', 'SSCL'] as $code) {
            $row = TaxCode::where('code', $code)->first();
            expect($row->is_active)->toBeFalse()->and($row->rates()->count())->toBe(0);
            failsWith('masterdata.no_tax_rate', fn () => $this->tax->rate($row->id, $this->on));
        }
    });

    it('adds tax to a net amount and splits a gross amount', function () {
        $net = $this->tax->fromNet('1000.00', [$this->vat], $this->on);
        $gross = $this->tax->fromGross('1180.00', [$this->vat], $this->on);

        expect($net->net)->toBe('1000.00')->and($net->tax)->toBe('180.00')->and($net->gross)->toBe('1180.00')
            ->and($net->lines)->toBe([['tax_code_id' => $this->vat, 'rate' => '18.0000', 'amount' => '180.00']])
            ->and($gross->net)->toBe('1000.00')->and($gross->tax)->toBe('180.00')->and($gross->gross)->toBe('1180.00');
    });

    it('rounds half up and always reconciles net + tax = gross', function () {
        expect($this->tax->fromNet('0.05', [$this->vat], $this->on)->tax)->toBe('0.01');          // 0.009 → 0.01

        foreach (['0.01', '1.00', '9.99', '123.45', '99999.99', '1234567.89'] as $gross) {
            $r = $this->tax->fromGross($gross, [$this->vat], $this->on);
            expect(bcadd($r->net, $r->tax, 2))->toBe($gross);
        }
    });

    it('combines several codes, each on the net amount', function () {
        $nbt = TaxCode::where('code', 'NBT')->first();
        $nbt->update(['is_active' => true]);
        app(AddTaxRate::class)($nbt, '2.0000', '2024-01-01');

        $r = $this->tax->fromNet('1000.00', [$this->vat, $nbt->id], $this->on);
        $g = $this->tax->fromGross('1200.00', [$this->vat, $nbt->id], $this->on);

        expect($r->tax)->toBe('200.00')->and($r->gross)->toBe('1200.00')->and(array_column($r->lines, 'amount'))->toBe(['180.00', '20.00'])
            ->and($g->net)->toBe('1000.00')->and(array_sum(array_column($g->lines, 'amount')))->toEqual(200.0);
    });

    it('follows a rate change on its effective date', function () {
        app(AddTaxRate::class)(TaxCode::find($this->vat), '20.0000', '2027-04-01');
        $before = CarbonImmutable::parse('2027-03-31', 'Asia/Colombo');
        $after = CarbonImmutable::parse('2027-04-01', 'Asia/Colombo');

        expect($this->tax->rate($this->vat, $before))->toBe('18.0000')->and($this->tax->rate($this->vat, $after))->toBe('20.0000')
            ->and(TaxCode::find($this->vat)->rates()->get()->map(fn ($r) => [$r->effective_from->toDateString(), $r->effective_to?->toDateString()])->all())
            ->toBe([['2027-04-01', null], ['2024-01-01', '2027-03-31']]);   // the old rate was closed the day before
    });
});

describe('price resolution', function () {
    beforeEach(function () {
        $this->prices = app(PriceResolver::class);
        $this->item = mdItem(['name' => 'Widget', 'uoms' => [['uom' => 'BOX', 'factor' => '12']]]);
        $this->list = mdPriceList(['is_default' => true]);
        mdPrices($this->list, [
            ['item' => $this->item->code, 'min_qty' => '0', 'unit_price' => '100'],
            ['item' => $this->item->code, 'min_qty' => '10', 'unit_price' => '90'],
            ['item' => $this->item->code, 'min_qty' => '100', 'unit_price' => '80.5'],
        ]);
    });

    it('picks the quantity break', function () {
        $price = fn (string $qty) => $this->prices->quote($this->item->id, $qty)?->unitPrice;

        expect($price('1'))->toBe('100.000000')->and($price('9.999999'))->toBe('100.000000')->and($price('10'))->toBe('90.000000')
            ->and($price('99'))->toBe('90.000000')->and($price('100'))->toBe('80.500000')->and($price('5000'))->toBe('80.500000');
    });

    it('describes where the price came from', function () {
        $quote = $this->prices->quote($this->item->id, '1');

        expect($quote->priceListCode)->toBe($this->list->code)->and($quote->currencyCode)->toBe('LKR')->and($quote->taxInclusive)->toBeFalse()
            ->and($quote->minQty)->toBe('0.000000')->and($quote->uomId)->toBe($this->item->base_uom_id);
    });

    it('derives a price for another unit from the base-unit price and uses a unit\'s own price when set', function () {
        $box = UnitOfMeasure::where('code', 'BOX')->value('id');

        // 1 box = 12 pcs; 12 pcs falls in the "10+" break at 90 → 1080 per box
        expect($this->prices->quote($this->item->id, '1', $box)->unitPrice)->toBe('1080.000000');

        mdPrices($this->list, [['item' => $this->item->code, 'uom' => 'BOX', 'min_qty' => '0', 'unit_price' => '1000']]);
        expect($this->prices->quote($this->item->id, '1', $box)->unitPrice)->toBe('1000.000000');
    });

    it('returns nothing when there is no price, no list, or the item is unknown', function () {
        $other = mdItem();

        expect($this->prices->quote($other->id, '1'))->toBeNull()
            ->and($this->prices->quote(999999, '1'))->toBeNull()
            ->and($this->prices->quote($this->item->id, '1', type: 'purchase'))->toBeNull();      // no purchase list
    });

    it('prefers the customer\'s own list and falls back to the default when it cannot be used', function () {
        $vip = mdPriceList(['name' => 'VIP']);
        mdPrices($vip, [['item' => $this->item->code, 'unit_price' => '70']]);
        $customer = app(SaveCustomer::class)(app(InputMapper::class)->customer(['name' => 'Big Buyer', 'price_list' => $vip->code]));

        expect($this->prices->quote($this->item->id, '1', partyId: $customer->id)->unitPrice)->toBe('70.000000')
            ->and($this->prices->quote($this->item->id, '1', priceListId: $this->list->id, partyId: $customer->id)->unitPrice)->toBe('100.000000');

        $vip->forceFill(['is_active' => false])->save();
        expect($this->prices->quote($this->item->id, '1', partyId: $customer->id)->unitPrice)->toBe('100.000000');   // inactive → default
    });

    it('respects validity dates and refuses a list of the wrong type', function () {
        $dated = mdPriceList(['name' => 'Promo', 'valid_from' => '2026-12-01', 'valid_to' => '2026-12-31']);
        mdPrices($dated, [['item' => $this->item->code, 'unit_price' => '50']]);
        $in = CarbonImmutable::parse('2026-12-15', 'Asia/Colombo');
        $out = CarbonImmutable::parse('2027-01-01', 'Asia/Colombo');

        expect($this->prices->quote($this->item->id, '1', priceListId: $dated->id, on: $in)->unitPrice)->toBe('50.000000')
            ->and($this->prices->quote($this->item->id, '1', priceListId: $dated->id, on: $out))->toBeNull()
            ->and($this->prices->quote($this->item->id, '1', type: 'purchase', priceListId: $this->list->id))->toBeNull();
    });

    it('uses a supplier\'s purchase list for purchases', function () {
        $buy = mdPriceList(['name' => 'Buy', 'type' => 'purchase']);
        mdPrices($buy, [['item' => $this->item->code, 'unit_price' => '60']]);
        $supplier = app(SaveSupplier::class)(app(InputMapper::class)->supplier(['name' => 'ACME', 'price_list' => $buy->code]));

        expect($this->prices->quote($this->item->id, '1', type: 'purchase', partyId: $supplier->id)->unitPrice)->toBe('60.000000');
    });
});

describe('directories for other modules', function () {
    it('looks items up by id or code and insists on active ones for documents', function () {
        $item = mdItem(['name' => 'Sugar', 'base_uom' => 'KG']);
        $catalog = app(ItemCatalog::class);

        $info = $catalog->find($item->id);
        expect($info->code)->toBe($item->code)->and($info->baseUomCode)->toBe('KG')->and($info->baseUomDecimals)->toBe(3)
            ->and($info->isStocked())->toBeTrue()->and($catalog->findByCode($item->code)->id)->toBe($item->id)
            ->and($catalog->find(999999))->toBeNull()->and($catalog->requireActive($item->id)->id)->toBe($item->id);

        $item->forceFill(['is_active' => false])->save();
        failsWith('masterdata.item_inactive', fn () => $catalog->requireActive($item->id));
        failsWith('masterdata.item_not_found', fn () => $catalog->requireActive(999999));
    });

    it('exposes parties without contact or bank details', function () {
        $terms = PaymentTerm::where('code', 'NET30')->value('id');
        $customer = app(SaveCustomer::class)(app(InputMapper::class)->customer(['name' => 'Buyer', 'payment_terms' => 'NET30', 'currency' => 'USD', 'credit_limit' => '50000', 'tax_id' => 'V123']));
        $supplier = app(SaveSupplier::class)(app(InputMapper::class)->supplier(['name' => 'Seller']));
        $directory = app(PartyDirectory::class);

        $c = $directory->requireActiveCustomer($customer->id);
        expect($c->code)->toStartWith('CUS-')->and($c->paymentTermsDays)->toBe(30)->and($c->currencyCode)->toBe('USD')
            ->and($c->creditLimit)->toBe('50000.0000')->and($c->taxId)->toBe('V123')
            ->and($directory->requireActiveSupplier($supplier->id)->code)->toStartWith('SUP-')
            ->and($directory->supplier($supplier->id)->creditLimit)->toBeNull()
            ->and($terms)->not->toBeNull();

        $customer->forceFill(['is_active' => false])->save();
        failsWith('masterdata.customer_inactive', fn () => $directory->requireActiveCustomer($customer->id));
        failsWith('masterdata.supplier_not_found', fn () => $directory->requireActiveSupplier(999999));
    });
});
