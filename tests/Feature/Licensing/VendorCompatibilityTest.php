<?php

use App\Modules\Licensing\Exceptions\LicenceException;
use App\Modules\Licensing\Support\LicenceToken;

// A licence made by the separate vendor billing project (vendor-billing/; text kept in tests/Fixtures/vendor-billing-licence.txt), signed with a fixed test key. If the two projects
// ever disagree about the licence format or the signature, this fails (and so does vendor-billing's own twin test).
const VENDOR_TEST_PUBLIC_KEY = '6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=';

it('accepts a licence signed by the vendor billing project', function () {
    $token = trim((string) file_get_contents(base_path('tests/Fixtures/vendor-billing-licence.txt')));

    $payload = LicenceToken::verify($token, VENDOR_TEST_PUBLIC_KEY);

    expect($payload['installation'])->toBe('inst_compat')->and($payload['company'])->toBe('Compat Ltd')->and($payload['plan'])->toBe('Standard')
        ->and($payload['valid_from'])->toBe('2026-12-01')->and($payload['valid_until'])->toBe('2026-12-31')
        ->and($payload['max_users'])->toBeNull()->and($payload['modules'])->toBeNull();
});

it('rejects that licence under any other key, or when one character is changed', function () {
    $token = trim((string) file_get_contents(base_path('tests/Fixtures/vendor-billing-licence.txt')));
    [$otherPublic] = LicenceToken::generateKeys();

    expect(fn () => LicenceToken::verify($token, $otherPublic))->toThrow(LicenceException::class);
    $tampered = substr_replace($token, $token[20] === 'A' ? 'B' : 'A', 20, 1);
    expect(fn () => LicenceToken::verify($tampered, VENDOR_TEST_PUBLIC_KEY))->toThrow(LicenceException::class);
});
