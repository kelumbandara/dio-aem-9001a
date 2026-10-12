<?php

use App\Modules\Reporting\Exceptions\ReportingException;
use App\Modules\Reporting\Support\CsvExporter;
use App\Modules\Reporting\Support\Kpi;
use App\Modules\Reporting\Support\Period;
use App\Modules\Reporting\Support\PeriodResolver;
use Carbon\CarbonImmutable;

function rptRange(?string $preset, ?string $from = null, ?string $to = null): array
{
    $p = app(PeriodResolver::class)->resolve($preset, $from, $to);

    return [$p->from, $p->to];
}

function rptRejects(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (ReportingException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected reporting error {$code}.");
}

describe('periods', function () {
    beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00', 'Asia/Colombo')));      // a Wednesday

    it('resolves every preset in Colombo calendar days, both ends included', function () {
        expect(rptRange('today'))->toBe(['2026-11-04', '2026-11-04'])
            ->and(rptRange('yesterday'))->toBe(['2026-11-03', '2026-11-03'])
            ->and(rptRange('this_week'))->toBe(['2026-11-02', '2026-11-04'])
            ->and(rptRange('last_week'))->toBe(['2026-10-26', '2026-11-01'])
            ->and(rptRange('last_7'))->toBe(['2026-10-29', '2026-11-04'])
            ->and(rptRange('last_30'))->toBe(['2026-10-06', '2026-11-04'])
            ->and(rptRange('this_month'))->toBe(['2026-11-01', '2026-11-04'])
            ->and(rptRange('last_month'))->toBe(['2026-10-01', '2026-10-31'])
            ->and(rptRange('this_quarter'))->toBe(['2026-10-01', '2026-11-04'])
            ->and(rptRange('last_quarter'))->toBe(['2026-07-01', '2026-09-30']);
    });

    it('defaults to this month, and to custom when both dates are given', function () {
        expect(rptRange(null))->toBe(['2026-11-01', '2026-11-04'])->and(rptRange('', '2026-10-05', '2026-10-09'))->toBe(['2026-10-05', '2026-10-09']);
    });

    it('follows the financial year from 1 April, before and after the year turns', function () {
        expect(rptRange('this_year'))->toBe(['2026-04-01', '2026-11-04'])->and(rptRange('last_year'))->toBe(['2025-04-01', '2026-03-31']);
        $this->travelTo(CarbonImmutable::parse('2027-02-10 09:00', 'Asia/Colombo'));
        expect(rptRange('this_year'))->toBe(['2026-04-01', '2027-02-10'])->and(rptRange('last_year'))->toBe(['2025-04-01', '2026-03-31']);
        $this->travelTo(CarbonImmutable::parse('2027-04-01 09:00', 'Asia/Colombo'));
        expect(rptRange('this_year'))->toBe(['2027-04-01', '2027-04-01'])->and(rptRange('last_year'))->toBe(['2026-04-01', '2027-03-31']);
    });

    it('uses the Colombo date, not the UTC date, near midnight', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 20:00', 'UTC'));                    // 01:30 on the 5th in Colombo
        expect(rptRange('today'))->toBe(['2026-11-05', '2026-11-05']);
    });

    it('does not run off the end of short months', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00', 'Asia/Colombo'));
        expect(rptRange('last_month'))->toBe(['2026-02-01', '2026-02-28']);
        $this->travelTo(CarbonImmutable::parse('2028-03-30 10:00', 'Asia/Colombo'));
        expect(rptRange('last_month'))->toBe(['2028-02-01', '2028-02-29']);
    });

    it('compares with the same number of days before, or the same dates a year earlier', function () {
        $r = app(PeriodResolver::class);
        $p = $r->resolve('this_month');                                                          // 4 days
        expect($r->comparison($p, 'previous')->toArray())->toBe(['from' => '2026-10-28', 'to' => '2026-10-31', 'days' => 4])
            ->and($r->comparison($p, 'year')->toArray())->toBe(['from' => '2025-11-01', 'to' => '2025-11-04', 'days' => 4])
            ->and($r->comparison($p, 'none'))->toBeNull()->and($r->comparison($p, null)->from)->toBe('2026-10-28');
        expect((new Period('2026-02-01', '2026-02-28'))->previous()->toArray())->toBe(['from' => '2026-01-04', 'to' => '2026-01-31', 'days' => 28]);
        expect((new Period('2024-02-01', '2024-02-29'))->lastYear()->toArray())->toBe(['from' => '2023-02-01', 'to' => '2023-02-28', 'days' => 28]);
        expect((new Period('2026-05-05', '2026-05-05'))->days())->toBe(1);
    });

    it('refuses nonsense with a clear error', function () {
        rptRejects(fn () => rptRange('next_year'), 'reporting.period_invalid');
        rptRejects(fn () => rptRange('custom', '2026-02-30', '2026-03-01'), 'reporting.date_invalid');
        rptRejects(fn () => rptRange('custom', '2026-03-01', 'soon'), 'reporting.date_invalid');
        rptRejects(fn () => rptRange('custom', null, null), 'reporting.date_invalid');
        rptRejects(fn () => rptRange('custom', '2026-03-05', '2026-03-01'), 'reporting.period_invalid');
        rptRejects(fn () => rptRange('custom', '2020-01-01', '2026-03-01'), 'reporting.period_too_long');
        rptRejects(fn () => app(PeriodResolver::class)->comparison(new Period('2026-01-01', '2026-01-31'), 'tomorrow'), 'reporting.compare_invalid');
        expect(rptRange('custom', '2024-11-04', '2026-11-04'))->toBe(['2024-11-04', '2026-11-04']);   // exactly 731 days is allowed
    });
});

describe('key figures', function () {
    it('works out the change in money, counts and quantities as a percentage', function () {
        $up = Kpi::make('a', 'A', 'lkr', '1200.00', '1000.00');
        expect($up['trend'])->toBe('up')->and($up['change'])->toBe('200')->and($up['change_pct'])->toBe('20.0')->and($up['good'])->toBeTrue();
        $down = Kpi::make('a', 'A', 'count', '3', '4');
        expect($down['trend'])->toBe('down')->and($down['change'])->toBe('-1')->and($down['change_pct'])->toBe('-25.0')->and($down['good'])->toBeFalse();
        $flat = Kpi::make('a', 'A', 'qty', '5', '5.000');
        expect($flat['trend'])->toBe('flat')->and($flat['good'])->toBeNull()->and($flat['change_pct'])->toBe('0.0');
    });

    it('reports rates in percentage points and not as a percent of a percent', function () {
        $k = Kpi::make('r', 'Reject rate', 'pct', '4.0', '5.0', false);
        expect($k['change'])->toBe('-1')->and($k['change_pct'])->toBeNull()->and($k['good'])->toBeTrue();       // lower is better, so falling is good
    });

    it('is honest when there is nothing to compare with', function () {
        $fromZero = Kpi::make('a', 'A', 'lkr', '500', '0');
        expect($fromZero['trend'])->toBe('up')->and($fromZero['change_pct'])->toBeNull()->and($fromZero['change'])->toBe('500');
        $none = Kpi::make('a', 'A', 'lkr', '500');
        expect($none['trend'])->toBeNull()->and($none['change'])->toBeNull()->and($none['good'])->toBeNull();
        $noValue = Kpi::make('a', 'A', 'pct', null, '5');
        expect($noValue['value'])->toBeNull()->and($noValue['trend'])->toBeNull();
        expect(Kpi::make('a', 'A', 'lkr', '5', '3', null)['good'])->toBeNull();                                  // neutral measures are never green or red
    });

    it('measures a swing from loss to profit against the size of the loss', function () {
        $k = Kpi::make('p', 'Profit', 'lkr', '50', '-100');
        expect($k['trend'])->toBe('up')->and($k['change'])->toBe('150')->and($k['change_pct'])->toBe('150.0')->and($k['good'])->toBeTrue();
        $worse = Kpi::make('p', 'Profit', 'lkr', '-300', '-100');
        expect($worse['trend'])->toBe('down')->and($worse['change_pct'])->toBe('-200.0')->and($worse['good'])->toBeFalse();
    });

    it('keeps money exact where floats would drift', function () {
        $k = Kpi::make('m', 'M', 'lkr', '0.30', '0.10');
        expect($k['change'])->toBe('0.2');
    });

    it('groups a daily series into weeks starting Monday, or months, without losing any value', function () {
        $daily = [];
        for ($d = CarbonImmutable::parse('2026-10-29'); $d->lessThanOrEqualTo(CarbonImmutable::parse('2026-11-10')); $d = $d->addDay()) {
            $daily[] = ['date' => $d->toDateString(), 'net' => '10'];
        }
        $weeks = Kpi::bucket($daily, 'net', 'week');
        expect(array_column($weeks, 'date'))->toBe(['2026-10-26', '2026-11-02', '2026-11-09'])->and(array_column($weeks, 'value'))->toBe(['40', '70', '20']);
        $months = Kpi::bucket($daily, 'net', 'month');
        expect(array_column($months, 'date'))->toBe(['2026-10-01', '2026-11-01'])->and(array_column($months, 'value'))->toBe(['30', '100']);
        expect(array_sum(array_column($weeks, 'value')))->toBe(130);
    });

    it('chooses days for short periods, weeks for a year and months beyond', function () {
        expect(Kpi::grain(1))->toBe('day')->and(Kpi::grain(62))->toBe('day')->and(Kpi::grain(63))->toBe('week')->and(Kpi::grain(400))->toBe('week')->and(Kpi::grain(401))->toBe('month');
    });
});

describe('CSV export', function () {
    $data = fn (array $rows, array $kpis = []) => ['kpis' => $kpis, 'charts' => [], 'tables' => [[
        'id' => 't', 'title' => 'Best customers', 'columns' => [['key' => 'name', 'label' => 'Customer', 'type' => 'text'], ['key' => 'net', 'label' => 'Net', 'type' => 'lkr'], ['key' => 'late', 'label' => 'Late', 'type' => 'flag']],
        'rows' => array_map(fn ($r) => ['cells' => $r, 'url' => null], $rows),
    ]]];

    it('starts with a byte-order mark so Excel reads the letters correctly, and keeps raw numbers', function () use ($data) {
        $csv = CsvExporter::render('Sales', new Period('2026-11-01', '2026-11-04'), null, $data([['name' => 'Lanka Traders', 'net' => '1234.50', 'late' => false]], [Kpi::make('net_sales', 'Net sales', 'lkr', '1234.50')]));
        expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
        $rows = array_map('str_getcsv', explode("\r\n", rtrim(substr($csv, 3), "\r\n")));
        expect($rows[0])->toBe(['Sales'])->and($rows[1])->toBe(['Period', '2026-11-01', '2026-11-04'])
            ->and(collect($rows)->contains(fn ($r) => $r[0] === 'Net sales' && $r[1] === '1234.50' && $r[2] === 'lkr'))->toBeTrue()
            ->and(collect($rows)->contains(fn ($r) => $r === ['Lanka Traders', '1234.50', 'No']))->toBeTrue();
    });

    it('quotes commas, quotes and line breaks', function () use ($data) {
        $csv = CsvExporter::render('S', new Period('2026-11-01', '2026-11-04'), null, $data([['name' => "Perera, \"Sons\"\nColombo", 'net' => '1', 'late' => true]]));
        $h = fopen('php://temp', 'r+');
        fwrite($h, substr($csv, 3));
        rewind($h);
        $cells = [];
        while (($row = fgetcsv($h, null, ',', '"', '\\')) !== false) {
            $cells = array_merge($cells, $row);
        }
        expect($cells)->toContain("Perera, \"Sons\"\nColombo");
    });

    it('stops a spreadsheet running names that look like formulas', function () use ($data) {
        foreach (['=HYPERLINK("http://evil","x")', '+cmd|calc', '-2+3', '@SUM(A1)', "\tTAB", "\rCR"] as $evil) {
            expect(CsvExporter::cell($evil))->toBe("'".$evil);
        }
        expect(CsvExporter::cell('-12.50'))->toBe('-12.50')->and(CsvExporter::cell('0'))->toBe('0')->and(CsvExporter::cell(null))->toBe('')->and(CsvExporter::cell(5))->toBe('5')
            ->and(CsvExporter::cell('Normal'))->toBe('Normal')->and(CsvExporter::cell(''))->toBe('');
        $csv = CsvExporter::render('S', new Period('2026-11-01', '2026-11-04'), null, $data([['name' => '=1+1', 'net' => '1', 'late' => false]]));
        expect($csv)->toContain("'=1+1")->and($csv)->not->toContain(',=1+1');
    });

    it('names the comparison period when there is one', function () use ($data) {
        $csv = CsvExporter::render('S', new Period('2026-11-01', '2026-11-04'), new Period('2026-10-28', '2026-10-31'), $data([]));
        expect($csv)->toContain('2026-10-28');
    });
});
