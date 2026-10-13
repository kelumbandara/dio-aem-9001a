<?php

use App\Support\MoneyText;
use Illuminate\Support\Facades\Blade;

it('formats like the money component: separators, two places, half-up, negatives, nothing', function () {
    expect(MoneyText::format('1234567.895'))->toBe('1,234,567.90')->and(MoneyText::format('0'))->toBe('0.00')->and(MoneyText::format('-1234.5'))->toBe('-1,234.50')
        ->and(MoneyText::format('-0.004'))->toBe('0.00')->and(MoneyText::format('12345678901234567890.12'))->toBe('12,345,678,901,234,567,890.12')
        ->and(MoneyText::format(null))->toBeNull()->and(MoneyText::format('abc'))->toBeNull()->and(MoneyText::format('1e5'))->toBeNull();
    expect(MoneyText::cell('1500'))->toBe('<span class="whitespace-nowrap tabular-nums">LKR 1,500.00</span>')->and(MoneyText::cell(null))->toContain('—');
});

it('gives the very same text as the component for the same value', function () {
    foreach (['0', '5', '999.994', '999.995', '-1000', '1234567.891', '-0.005'] as $v) {
        $viaComponent = trim(strip_tags(Blade::render('<x-core::money :value="$v" />', ['v' => $v])));
        expect(trim(strip_tags(MoneyText::cell($v))))->toBe($viaComponent);
    }
});
