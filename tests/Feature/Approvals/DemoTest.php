<?php

use App\Modules\Approvals\Actions\SavePolicy;
use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\Database\Seeders\DemoApprovalSeeder;
use App\Modules\Approvals\Demo\DemoExpense;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Core\Database\Seeders\DemoStaffSeeder;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\User;

it('is not exposed outside the local environment', function () {
    expect(app()->environment('local'))->toBeFalse()
        ->and(Route::has('approvals.demo'))->toBeFalse()
        ->and(app(ApprovableRegistry::class)->has(DemoExpense::KEY))->toBeFalse();

    $this->actingAs(adminUser())->get('/dev/approvals-demo')->assertNotFound();
});

it('refuses to seed demo users anywhere but local', function () {
    $this->app['env'] = 'production';
    app(DemoStaffSeeder::class)->run();
    app(DemoApprovalSeeder::class)->run(app(SavePolicy::class));

    expect(User::where('email', 'like', '%@dio.test')->count())->toBe(0)->and(ApprovalPolicy::count())->toBe(0);
});

it('seeds working demo users and a policy for the demo action, and the whole flow runs', function () {
    $this->app['env'] = 'local';
    app(ApprovalService::class)->registerAction(DemoExpense::class);
    app(DemoStaffSeeder::class)->run();
    app(DemoApprovalSeeder::class)->run(app(SavePolicy::class));
    app(DemoApprovalSeeder::class)->run(app(SavePolicy::class));   // idempotent

    $requester = User::where('email', 'requester@dio.test')->firstOrFail();
    $approver = User::where('email', 'approver@dio.test')->firstOrFail();
    expect(ApprovalPolicy::where('action_key', DemoExpense::KEY)->count())->toBe(1)
        ->and($requester->hasRole('sales'))->toBeTrue()->and($approver->hasRole('finance-approver'))->toBeTrue()
        ->and($approver->requiresMfa())->toBeFalse();      // so the owner can sign in without an authenticator

    $decision = app(ApprovalService::class)->gate(DemoExpense::KEY, ['description' => 'Chairs', 'amount' => '25000.00'], $requester->id);
    expect($decision->requiresApproval)->toBeTrue();

    app(ApprovalEngine::class)->approve($decision->requestId, $approver->id);

    expect(ApprovalRequest::sole()->status)->toBe('executed')
        ->and(AuditLog::where('event', 'demo.expense.released')->count())->toBe(1);

    expect(app(ApprovalService::class)->gate(DemoExpense::KEY, ['description' => 'Pens', 'amount' => '200'], $requester->id)->requiresApproval)->toBeFalse();
});
