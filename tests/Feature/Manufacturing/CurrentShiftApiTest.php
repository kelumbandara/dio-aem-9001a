<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Manufacturing\Services\SetupService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $setup = app(SetupService::class);
    $setup->save('shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'days' => '1111100']);        // Monday to Friday
    $setup->save('shifts', ['code' => 'NIGHT', 'name' => 'Night', 'start_time' => '20:00', 'end_time' => '05:00', 'days' => '1111111']);
    $this->operator = userWithPermissions('manufacturing.view', 'manufacturing.output.report');
    $this->at = fn (string $when) => $this->travelTo(CarbonImmutable::parse($when, 'Asia/Colombo'));
});

it('tells the phone which shift is running and its shift date', function () {
    ($this->at)('2026-11-04 10:00');                                                  // a Wednesday
    asApi($this->operator)->getJson('/api/v1/shifts/current')->assertOk()->assertExactJson(['data' => ['shift' => 'DAY', 'name' => 'Day', 'date' => '2026-11-04']]);
    ($this->at)('2026-11-04 21:30');
    asApi($this->operator)->getJson('/api/v1/shifts/current')->assertOk()->assertJsonPath('data.shift', 'NIGHT')->assertJsonPath('data.date', '2026-11-04');
});

it('gives a night shift after midnight the date of the evening it started', function () {
    ($this->at)('2026-11-05 02:15');
    asApi($this->operator)->getJson('/api/v1/shifts/current')->assertOk()->assertJsonPath('data.shift', 'NIGHT')->assertJsonPath('data.date', '2026-11-04');
});

it('answers null between shifts and on a day a shift does not work', function () {
    ($this->at)('2026-11-04 06:00');
    asApi($this->operator)->getJson('/api/v1/shifts/current')->assertOk()->assertExactJson(['data' => null]);
    ($this->at)('2026-11-07 10:00');                                                  // a Saturday: no day shift
    asApi($this->operator)->getJson('/api/v1/shifts/current')->assertOk()->assertExactJson(['data' => null]);
});

it('needs the permission and a sign-in', function () {
    $this->getJson('/api/v1/shifts/current')->assertUnauthorized();
    asApi(userWithPermissions('sales.view'))->getJson('/api/v1/shifts/current')->assertForbidden();
});
