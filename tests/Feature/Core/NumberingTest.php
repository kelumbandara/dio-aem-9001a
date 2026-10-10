<?php

use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Core\Exceptions\NumberingException;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyProfile;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->numbers = app(NumberingService::class);
    CompanyProfile::query()->forceCreate(['id' => 1, 'legal_name' => 'Test Co', 'fiscal_year_start_month' => 4]);
});

it('allocates consecutive padded numbers', function () {
    $this->numbers->define('test.doc', 'DOC-{SEQ}', 4);

    expect($this->numbers->next('test.doc'))->toBe('DOC-0001')
        ->and($this->numbers->next('test.doc'))->toBe('DOC-0002');
});

it('keeps separate counters per sequence', function () {
    $this->numbers->define('a', 'A{SEQ}', 3);
    $this->numbers->define('b', 'B{SEQ}', 3);

    $this->numbers->next('a');
    $this->numbers->next('a');

    expect($this->numbers->next('b'))->toBe('B001');
});

it('does not overwrite an existing definition when defined again', function () {
    $this->numbers->define('test.doc', 'ONE-{SEQ}', 3);
    $this->numbers->define('test.doc', 'TWO-{SEQ}', 6);

    expect($this->numbers->next('test.doc'))->toBe('ONE-001');
});

it('fails loudly for unknown or inactive sequences', function () {
    expect(fn () => $this->numbers->next('missing'))->toThrow(NumberingException::class);
});

it('resets yearly', function () {
    $this->numbers->define('inv', '{YYYY}/{SEQ}', 3, 'yearly');

    expect($this->numbers->next('inv', null, CarbonImmutable::parse('2026-06-01')))->toBe('2026/001')
        ->and($this->numbers->next('inv', null, CarbonImmutable::parse('2026-12-31')))->toBe('2026/002')
        ->and($this->numbers->next('inv', null, CarbonImmutable::parse('2027-01-02')))->toBe('2027/001');
});

it('resets monthly', function () {
    $this->numbers->define('rcpt', '{YY}{MM}-{SEQ}', 2, 'monthly');

    expect($this->numbers->next('rcpt', null, CarbonImmutable::parse('2026-05-10')))->toBe('2605-01')
        ->and($this->numbers->next('rcpt', null, CarbonImmutable::parse('2026-06-10')))->toBe('2606-01');
});

it('follows the April–March fiscal year', function () {
    $this->numbers->define('po', 'PO/{FY}/{SEQ}', 3, 'fiscal_yearly');

    expect($this->numbers->next('po', null, CarbonImmutable::parse('2026-03-31 12:00', 'Asia/Colombo')))->toBe('PO/2025-26/001')
        ->and($this->numbers->next('po', null, CarbonImmutable::parse('2026-04-01 12:00', 'Asia/Colombo')))->toBe('PO/2026-27/001')
        ->and($this->numbers->next('po', null, CarbonImmutable::parse('2027-03-31 12:00', 'Asia/Colombo')))->toBe('PO/2026-27/002');
});

it('decides the period in Colombo time, not UTC', function () {
    $this->numbers->define('po', 'PO/{FY}/{SEQ}', 3, 'fiscal_yearly');

    // 2026-03-31 20:00 UTC is already 1 April 01:30 in Sri Lanka.
    expect($this->numbers->next('po', null, CarbonImmutable::parse('2026-03-31 20:00', 'UTC')))->toBe('PO/2026-27/001');
});

it('uses a plain calendar year label when the fiscal year starts in January', function () {
    CompanyProfile::query()->find(1)->update(['fiscal_year_start_month' => 1]);
    $this->numbers->define('po', 'PO/{FY}/{SEQ}', 3, 'fiscal_yearly');

    expect($this->numbers->next('po', null, CarbonImmutable::parse('2026-02-01')))->toBe('PO/2026/001');
});

it('counts per branch when asked to and renders the branch code', function () {
    $colombo = Branch::factory()->create(['code' => 'CMB']);
    $kandy = Branch::factory()->create(['code' => 'KDY']);
    $this->numbers->define('so', '{BR}-{SEQ}', 3, 'never', perBranch: true);

    expect($this->numbers->next('so', $colombo->id))->toBe('CMB-001')
        ->and($this->numbers->next('so', $colombo->id))->toBe('CMB-002')
        ->and($this->numbers->next('so', $kandy->id))->toBe('KDY-001');
});

it('does not burn a number when the surrounding transaction rolls back', function () {
    $this->numbers->define('gapless', '{SEQ}', 3);

    try {
        DB::transaction(function () {
            expect($this->numbers->next('gapless'))->toBe('001');
            throw new RuntimeException('document failed to save');
        });
    } catch (RuntimeException) {
    }

    expect($this->numbers->next('gapless'))->toBe('001');
});
