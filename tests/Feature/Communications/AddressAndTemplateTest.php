<?php

use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Services\Address;
use App\Modules\Communications\Services\TemplateRenderer;

describe('phone numbers', function () {
    it('puts every way of writing the same Sri Lankan number in one form', function () {
        foreach (['077 123 4567', '0771234567', '+94 77 123 4567', '+94771234567', '94771234567', '0094 77 123 4567', '771234567', '(077) 123-4567', ' 077.123.4567 '] as $raw) {
            expect(Address::phone($raw))->toBe('94771234567', $raw);
        }
        expect(Address::phone('011 234 5678'))->toBe('94112345678');                      // a landline keeps its area code
    });

    it('keeps foreign numbers as they are', function () {
        expect(Address::phone('+44 20 7946 0958'))->toBe('442079460958')->and(Address::phone('+1 (415) 555-2671'))->toBe('14155552671')->and(Address::phone('0091 98765 43210'))->toBe('919876543210');
    });

    it('refuses what cannot be a phone number', function () {
        foreach (['', '   ', 'abc', '077 123', '0771234567890123', '+94 77 123 45678', '+0 123 456 789', '12', '077-ABC-4567', "077 123 4567\n--", '077 123 4567; DROP', '94 7712345'] as $bad) {
            expect(Address::phone($bad))->toBeNull($bad);
        }
    });

    it('uses the country code that is configured', function () {
        config(['communications.default_country_code' => '91']);
        expect(Address::normalize('whatsapp', '098765 43210'))->toBe('919876543210');
    });

    it('shows a number with a plus and hides the middle in lists', function () {
        expect(Address::display('whatsapp', '94771234567'))->toBe('+94771234567')->and(Address::mask('whatsapp', '94771234567'))->toBe('94••••••567')
            ->and(Address::mask('email', 'buyer@lanka.test'))->toBe('b•••@lanka.test')->and(Address::display('email', 'a@b.lk'))->toBe('a@b.lk');
    });
});

describe('e-mail addresses', function () {
    it('trims and lower-cases, and refuses what is not an address', function () {
        expect(Address::email('  Buyer@Lanka.TEST '))->toBe('buyer@lanka.test');
        foreach (['', 'buyer', 'buyer@', '@lanka.test', 'a b@c.lk', "a@b.lk\nBcc: x@y.z", 'a@b', str_repeat('a', 200).'@b.lk'] as $bad) {
            expect(Address::email($bad))->toBeNull($bad);
        }
        expect(Address::normalize('sms', '123'))->toBeNull();
    });
});

describe('filling templates', function () {
    $r = new TemplateRenderer;

    it('lists the values a text needs, once each, in order', function () use ($r) {
        expect($r->placeholders('Dear {name}, {document_no} is due {due_date}. {name}, thanks. {Not_A_Value} {x y}'))->toBe(['name', 'document_no', 'due_date']);
    });

    it('fills them in', function () use ($r) {
        expect($r->render('Dear {name}, pay {amount}.', ['name' => 'Amal', 'amount' => 'LKR 1,000.00', 'unused' => 'x']))->toBe('Dear Amal, pay LKR 1,000.00.');
    });

    it('refuses a missing or empty value instead of leaving a gap', function () use ($r) {
        foreach ([[], ['name' => ''], ['name' => '   '], ['name' => null]] as $params) {
            try {
                $r->render('Dear {name}', $params);
                $this->fail('Expected a refusal');
            } catch (CommunicationsException $e) {
                expect($e->errorCode)->toBe('comm.param_missing')->and($e->details[0]['field'])->toBe('params.name');
            }
        }
    });

    it('lets a value add neither lines nor control characters, and cuts a long one', function () use ($r) {
        $out = $r->render('To: {name}', ['name' => "Amal\r\nBcc: attacker@evil.test\x00\x07 and more"]);
        expect($out)->toBe('To: Amal Bcc: attacker@evil.test and more')->and($out)->not->toContain("\n");
        expect(mb_strlen($r->render('{x}', ['x' => str_repeat('a', 1000)])))->toBe(301);
    });

    it('does not fill a value that itself looks like a placeholder, so one value cannot reach another', function () use ($r) {
        expect($r->render('{name} {code}', ['name' => '{code}', 'code' => '123456']))->toBe('{code} 123456');
    });
});
