<?php

use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Services\ApprovalEngine;
use Tests\Support\Approvals\FakePayment;

beforeEach(function () {
    $this->requester = userWithRole('sales');
    $this->approver = userWithRole('finance-approver');
    paymentPolicy();
    $this->request = approvalRequest(requestPayment($this->requester, '12345.6789'));
});

describe('inbox and detail pages', function () {
    it('shows an approver their inbox and the request detail with the decision form', function () {
        $this->actingAs($this->approver)->get('/approvals')->assertOk()
            ->assertSee($this->request->document_no)->assertSee('Release payment 12345.6789 to ACME')
            ->assertSee('LKR 12,345.68');                       // half-up display of the decimal string

        $this->actingAs($this->approver)->get(route('approvals.show', $this->request))->assertOk()
            ->assertSee('Your decision')->assertSee('Approve')->assertSee('Reject')
            ->assertSee($this->request->payload_hash);
    });

    it('does not show the decision form to the requester, outsiders or after the decision', function () {
        $this->actingAs($this->requester)->get(route('approvals.show', $this->request))->assertOk()->assertDontSee('Your decision')->assertSee('Cancel request');
        $this->actingAs(userWithRole('storekeeper'))->get(route('approvals.show', $this->request))->assertForbidden();
        $this->actingAs(userWithRole('storekeeper'))->get('/approvals')->assertOk()->assertDontSee($this->request->document_no);

        app(ApprovalEngine::class)->approve($this->request->public_id, $this->approver->id);
        $this->actingAs($this->approver)->get(route('approvals.show', $this->request))->assertDontSee('Your decision')->assertSee('Done');
    });

    it('lets super-admin look but gives them no decision form', function () {
        $this->actingAs(adminUser('super-admin'))->get(route('approvals.show', $this->request))->assertOk()->assertDontSee('Your decision');
    });

    it('shows requesters their own requests and reserves the all tab', function () {
        $this->actingAs($this->requester)->get('/approvals?scope=mine')->assertOk()->assertSee($this->request->document_no);
        $this->actingAs($this->requester)->get('/approvals?scope=all')->assertForbidden();
        $this->actingAs(userWithPermissions('approvals.request.view-all'))->get('/approvals?scope=all')->assertOk()->assertSee($this->request->document_no);
        $this->actingAs($this->requester)->get('/approvals?scope=bogus')->assertNotFound();
    });

    it('requires a login', function () {
        $this->get('/approvals')->assertRedirect(route('login'));
        $this->get(route('approvals.show', $this->request))->assertRedirect(route('login'));
    });
});

describe('deciding in the browser', function () {
    it('approves with a comment and runs the action', function () {
        $this->actingAs($this->approver)->post(route('approvals.approve', $this->request), ['comment' => 'fine'])
            ->assertRedirect(route('approvals.show', $this->request))->assertSessionHas('status', 'Your approval was recorded.');

        expect($this->request->fresh()->status)->toBe('executed')->and(FakePayment::$executed)->toHaveCount(1);
    });

    it('turns a refusal into a readable message instead of an error page', function () {
        $this->actingAs(userWithRole('finance-approver'))->post(route('approvals.reject', $this->request), [])
            ->assertRedirect(route('approvals.show', $this->request))->assertSessionHasErrors('approval');
        expect($this->request->fresh()->status)->toBe('pending');
    });

    it('rejects with a reason', function () {
        $this->actingAs($this->approver)->post(route('approvals.reject', $this->request), ['comment' => 'Too expensive'])->assertSessionHas('status');
        expect($this->request->fresh()->status)->toBe('rejected');
    });

    it('shows a stranger a 403 when trying to decide', function () {
        $this->actingAs(userWithRole('storekeeper'))->post(route('approvals.approve', $this->request))->assertForbidden();
    });

    it('lets the requester cancel from the page', function () {
        $this->actingAs($this->requester)->post(route('approvals.cancel', $this->request))->assertSessionHas('status');
        expect($this->request->fresh()->status)->toBe('cancelled');
    });

    it('escapes everything shown from the payload', function () {
        $evil = approvalRequest(requestPayment($this->requester, '5000.0000', ['note' => '<script>alert(1)</script>']));

        $this->actingAs($this->approver)->get(route('approvals.show', $evil))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    });
});

describe('languages', function () {
    it('renders the approval pages in every language without leaking translation keys', function (string $locale) {
        $this->approver->update(['locale' => $locale]);
        app('auth')->forgetGuards();

        foreach (['/approvals', '/approvals?scope=mine', route('approvals.show', $this->request), '/approvals/delegations'] as $url) {
            $body = $this->actingAs($this->approver->fresh())->get($url)->assertOk()->getContent();
            expect($body)->not->toContain('approvals::')->not->toContain('approvals.ui.');
        }
    })->with(['en', 'si', 'ta']);

    it('has identical translation keys in every language', function () {
        $keys = fn (string $locale, string $file) => collect(array_keys(Arr::dot(require app_path("Modules/Approvals/Lang/{$locale}/{$file}.php"))))->sort()->values()->all();

        foreach (['ui', 'notifications'] as $file) {
            foreach (['si', 'ta'] as $locale) {
                expect($keys($locale, $file))->toBe($keys('en', $file), "{$locale}/{$file} differs from en");
            }
        }
    });

    it('notifies approvers with a working link into the page', function () {
        $notification = $this->approver->notifications()->first();

        $this->actingAs($this->approver)->post("/notifications/{$notification->id}/read")->assertRedirect(route('approvals.show', $this->request, absolute: false));
    });
});

describe('delegations page', function () {
    it('creates a delegation in Sri Lanka time and lets the delegator revoke it', function () {
        $delegate = userWithRole('assistant');

        $this->actingAs($this->approver)->post('/approvals/delegations', [
            'delegate_id' => $delegate->id, 'starts_at' => now('Asia/Colombo')->subMinutes(5)->format('Y-m-d\TH:i'),
            'ends_at' => now('Asia/Colombo')->addDays(2)->format('Y-m-d\TH:i'), 'action_key' => FakePayment::approvalKey(),
        ])->assertSessionHas('status');

        $delegation = ApprovalDelegation::sole();
        expect($delegation->ends_at->between(now()->addDays(2)->subMinutes(2), now()->addDays(2)->addMinutes(2)))->toBeTrue();

        $this->actingAs($this->approver)->get('/approvals/delegations')->assertOk()->assertSee($delegate->name);
        $this->actingAs($delegate)->delete("/approvals/delegations/{$delegation->id}")->assertForbidden();
        $this->actingAs($this->approver)->delete("/approvals/delegations/{$delegation->id}")->assertSessionHas('status');
        expect($delegation->fresh()->revoked_at)->not->toBeNull();
    });

    it('explains why a delegation was refused', function () {
        $this->actingAs($this->approver)->post('/approvals/delegations', [
            'delegate_id' => $this->approver->id, 'starts_at' => now()->format('Y-m-d\TH:i'), 'ends_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('delegation');
    });
});

describe('policy administration', function () {
    beforeEach(function () {
        $this->admin = adminUser();
        userWithRole('cfo');
    });

    function policyForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Large payments', 'action_key' => FakePayment::approvalKey(), 'min_amount' => '50000', 'max_amount' => '',
            'priority' => 50, 'expiry_hours' => 48, 'reminder_after_hours' => 12,
            'distinct_approvers' => '1', 'allow_self_approval' => '0', 'is_active' => '1', 'risk_levels' => ['R3'],
            'steps' => [
                ['order' => 1, 'type' => 'role', 'role' => 'finance-approver', 'user_id' => '', 'label' => 'Finance'],
                ['order' => 2, 'type' => 'role', 'role' => 'cfo', 'user_id' => '', 'label' => ''],
            ],
        ], $overrides);
    }

    it('is limited to people with the permission', function () {
        $this->actingAs($this->approver)->get('/admin/approvals/policies')->assertForbidden();
        $this->actingAs($this->approver)->post('/admin/approvals/policies', policyForm())->assertForbidden();
        $this->actingAs($this->approver)->get('/admin/approvals/authority')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/approvals/policies')->assertOk()->assertSee('Payments');
        $this->actingAs($this->admin)->get('/admin/approvals/policies/create')->assertOk()->assertSee(FakePayment::approvalKey());
    });

    it('creates a policy with ordered steps and uses it for new requests', function () {
        $this->actingAs($this->admin)->post('/admin/approvals/policies', policyForm())->assertRedirect(route('approvals.policies.index'));

        $policy = ApprovalPolicy::where('name', 'Large payments')->with('steps')->sole();
        expect($policy->min_amount)->toBe('50000.0000')->and($policy->max_amount)->toBeNull()
            ->and($policy->risk_levels)->toBe(['R3'])
            ->and($policy->steps->map(fn ($s) => [$s->step_order, $s->approver_role])->all())->toBe([[1, 'finance-approver'], [2, 'cfo']]);

        $big = approvalRequest(requestPayment($this->requester, '75000.0000'));
        expect($big->policy_snapshot['name'])->toBe('Large payments')->and($big->steps)->toHaveCount(2);
    });

    it('edits a policy, replacing its steps', function () {
        $policy = ApprovalPolicy::sole();

        $this->actingAs($this->admin)->get(route('approvals.policies.edit', $policy))->assertOk()->assertSee('finance-approver');
        $this->actingAs($this->admin)->put(route('approvals.policies.update', $policy), policyForm(['name' => 'Renamed', 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'cfo', 'user_id' => '', 'label' => '']]]))
            ->assertRedirect(route('approvals.policies.index'));

        $policy->refresh()->load('steps');
        expect($policy->name)->toBe('Renamed')->and($policy->steps)->toHaveCount(1)->and($policy->steps[0]->approver_role)->toBe('cfo');
        // requests already made keep the policy they were made under
        expect($this->request->fresh()->policy_snapshot['name'])->toBe('Payments');
    });

    it('validates input and explains domain problems', function () {
        $post = fn (array $o) => $this->actingAs($this->admin)->post('/admin/approvals/policies', policyForm($o));

        $post(['min_amount' => '12,5'])->assertSessionHasErrors('min_amount');
        $post(['steps' => []])->assertSessionHasErrors('steps');
        $post(['action_key' => 'no.such.action'])->assertSessionHasErrors('policy');
        $post(['min_amount' => '500', 'max_amount' => '100'])->assertSessionHasErrors('policy');
        $post(['steps' => [['order' => 1, 'type' => 'role', 'role' => 'ghost-role', 'user_id' => '', 'label' => '']]])->assertSessionHasErrors('policy');
        $post(['steps' => [['order' => 1, 'type' => 'user', 'role' => '', 'user_id' => '999999', 'label' => '']]])->assertSessionHasErrors('policy');

        expect(ApprovalPolicy::count())->toBe(1);
    });

    it('supports a named person as approver', function () {
        $person = userWithRole('assistant');

        $this->actingAs($this->admin)->post('/admin/approvals/policies', policyForm(['steps' => [['order' => 1, 'type' => 'user', 'role' => '', 'user_id' => (string) $person->id, 'label' => '']]]))
            ->assertRedirect();

        expect(ApprovalPolicy::where('name', 'Large payments')->sole()->steps[0]->approver_user_id)->toBe($person->id);
    });

    it('deletes a policy without disturbing open requests', function () {
        $policy = ApprovalPolicy::sole();

        $this->actingAs($this->admin)->delete(route('approvals.policies.destroy', $policy))->assertRedirect();

        expect(ApprovalPolicy::count())->toBe(0)->and(ApprovalPolicy::withTrashed()->count())->toBe(1)->and($this->request->fresh()->status)->toBe('pending');
        app(ApprovalEngine::class)->approve($this->request->public_id, $this->approver->id);
        expect($this->request->fresh()->status)->toBe('executed');
    });
});

describe('authority matrix administration', function () {
    beforeEach(fn () => $this->admin = adminUser());

    it('adds and removes limits for roles and people', function () {
        $this->actingAs($this->admin)->post('/admin/approvals/authority', ['holder' => 'role:finance-approver', 'action_key' => FakePayment::approvalKey(), 'max_amount' => '25000', 'note' => 'Standard'])->assertSessionHas('status');
        $this->actingAs($this->admin)->post('/admin/approvals/authority', ['holder' => 'user:'.$this->approver->id, 'action_key' => '*', 'max_amount' => ''])->assertSessionHas('status');

        $rows = ApprovalAuthority::orderBy('id')->get();
        expect($rows)->toHaveCount(2)->and($rows[0]->max_amount)->toBe('25000.0000')->and($rows[0]->role_name)->toBe('finance-approver')
            ->and($rows[1]->max_amount)->toBeNull()->and($rows[1]->user_id)->toBe($this->approver->id);

        $this->actingAs($this->admin)->get('/admin/approvals/authority')->assertOk()->assertSee('Standard')->assertSee('LKR 25,000.00');
        $this->actingAs($this->admin)->delete(route('approvals.authority.destroy', $rows[0]))->assertSessionHas('status');
        expect(ApprovalAuthority::count())->toBe(1);
    });

    it('validates holder, action and amount', function () {
        $post = fn (array $d) => $this->actingAs($this->admin)->post('/admin/approvals/authority', $d);

        $post(['holder' => 'role:ghost', 'action_key' => '*'])->assertSessionHasErrors('authority');
        $post(['holder' => 'user:999999', 'action_key' => '*'])->assertSessionHasErrors('authority');
        $post(['holder' => 'role:finance-approver', 'action_key' => 'no.such'])->assertSessionHasErrors('authority');
        $post(['holder' => 'role:finance-approver', 'action_key' => '*', 'max_amount' => '-5'])->assertSessionHasErrors('max_amount');
        $post(['holder' => 'finance-approver', 'action_key' => '*'])->assertSessionHasErrors('authority');
        expect(ApprovalAuthority::count())->toBe(0);
    });
});

it('appears in the sidebar for everyone, with admin pages only for people allowed to manage them', function () {
    $this->actingAs($this->approver)->get('/')->assertSee(route('approvals.index'), false)->assertSee(route('approvals.delegations.index'), false)
        ->assertDontSee(route('approvals.policies.index'), false);
    $this->actingAs(adminUser())->get('/')->assertSee(route('approvals.policies.index'), false)->assertSee(route('approvals.authority.index'), false);
});
