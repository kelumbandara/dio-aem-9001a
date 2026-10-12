<?php

use App\Modules\Core\Support\AppearanceOptions;
use App\Modules\Core\Support\ColorScale;

it('normalises hex colours and refuses anything else', function () {
    expect(ColorScale::normalise('#ABC'))->toBe('#aabbcc')->and(ColorScale::normalise('4F46E5'))->toBe('#4f46e5');
    foreach (['#abcd', 'red', '#12345g', '', '#12'] as $bad) {
        expect(ColorScale::isHex($bad))->toBeFalse($bad);
    }
    expect(ColorScale::isHex('#1D4ED8'))->toBeTrue();
});

it('builds a scale from lightest to darkest with the chosen colour as step 600', function () {
    $scale = ColorScale::scale('#4f46e5');

    expect(array_keys($scale))->toBe([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950])->and($scale[600])->toBe('#4f46e5');
    $light = array_map(fn (string $hex) => ColorScale::contrast($hex, '#ffffff'), $scale);
    expect($light[50])->toBeLessThan($light[100])->and($light[100])->toBeLessThan($light[300])->and($light[300])->toBeLessThan($light[600])
        ->and($light[600])->toBeLessThan($light[800])->and($light[800])->toBeLessThan($light[950]);
    expect($scale)->each->toMatch('/^#[0-9a-f]{6}$/');
});

it('measures contrast like WCAG', function () {
    expect(round(ColorScale::contrast('#000000', '#ffffff'), 1))->toBe(21.0)->and(round(ColorScale::contrast('#ffffff', '#ffffff'), 1))->toBe(1.0);
    expect(ColorScale::readableOnWhite('#1d4ed8'))->toBeTrue()->and(ColorScale::readableOnWhite('#ffff00'))->toBeFalse()->and(ColorScale::readableOnWhite('#93c5fd'))->toBeFalse();
});

it('offers only ready-made colours that are readable with white text', function () {
    foreach (AppearanceOptions::PRESETS as $id => $hex) {
        expect(ColorScale::readableOnWhite($hex))->toBeTrue("{$id} {$hex} is too light");
    }
    expect(AppearanceOptions::PRESETS['indigo'])->toBe(AppearanceOptions::DEFAULTS['primary']);
});
