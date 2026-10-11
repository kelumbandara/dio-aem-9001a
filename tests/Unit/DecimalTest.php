<?php

use App\Support\Decimal;

it('rounds half away from zero', function (string $in, int $places, string $out) {
    expect(Decimal::round($in, $places))->toBe($out);
})->with([
    ['1.005', 2, '1.01'], ['1.004999', 2, '1.00'], ['2.5', 0, '3'], ['2.4999', 0, '2'], ['-2.5', 0, '-3'],
    ['0.995', 2, '1.00'], ['12', 2, '12.00'], ['0', 2, '0.00'], ['-0.004', 2, '0.00'], ['-0.005', 2, '-0.01'],
    ['123456789.125', 2, '123456789.13'], ['0.0000005', 6, '0.000001'], ['99.995', 2, '100.00'],
]);

it('does arithmetic without floating point error', function () {
    expect(Decimal::add('0.1', '0.2'))->toBe('0.300000000000')
        ->and(Decimal::compare(Decimal::add('0.1', '0.2'), '0.3'))->toBe(0)   // 0.1 + 0.2 == 0.3 with decimals
        ->and(Decimal::mul('19.99', '3'))->toBe('59.970000000000')
        ->and(Decimal::div('1', '3'))->toBe('0.333333333333')
        ->and(Decimal::sub('10', '0.01'))->toBe('9.990000000000');
});

it('refuses anything that is not a plain decimal string', function (string $bad) {
    expect(fn () => Decimal::add($bad, '1'))->toThrow(InvalidArgumentException::class);
})->with(['1e3', '1,5', 'abc', '', ' 1', '1.', '--1']);

it('refuses to divide by zero', function () {
    expect(fn () => Decimal::div('1', '0.000'))->toThrow(InvalidArgumentException::class);
});

it('accepts whole numbers only when zero decimal places are allowed', function () {
    expect(Decimal::isDecimal('12', 0))->toBeTrue()->and(Decimal::isDecimal('12.0', 0))->toBeFalse()
        ->and(Decimal::isDecimal('-3', 0))->toBeTrue()->and(Decimal::isDecimal('1.5', 1))->toBeTrue()->and(Decimal::isDecimal('1.55', 1))->toBeFalse();
});
