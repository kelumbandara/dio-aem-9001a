<?php

use App\Modules\Core\Models\User;

it('redirects guests to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->get('/admin/modules')->assertRedirect(route('login'));
});

it('shows the login form', function () {
    $this->get('/login')->assertOk()->assertSee(__('core::auth.sign_in'));
});

it('signs a user in and out', function () {
    $user = User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);

    $this->post('/login', ['email' => 'amal@example.test', 'password' => 'secret-pass-1'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    $this->get('/')->assertOk()->assertSee($user->name);

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('keeps the email and shows an error for bad credentials', function () {
    User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);

    $this->from('/login')->post('/login', ['email' => 'amal@example.test', 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email')
        ->assertSessionHasInput('email', 'amal@example.test');
    $this->assertGuest();
});

it('asks for the authentication code when MFA is on', function () {
    $user = User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);
    enrolMfa($user);

    $this->from('/login')->post('/login', ['email' => 'amal@example.test', 'password' => 'secret-pass-1'])
        ->assertSessionHasErrors('mfa_code');
    $this->assertGuest();
});

it('sends privileged users without MFA to the setup page and shows a QR code', function () {
    $admin = User::factory()->withRole('admin')->create();

    $this->actingAs($admin)->get('/')->assertRedirect(route('mfa.setup'));
    $this->actingAs($admin)->get(route('mfa.setup'))->assertOk()->assertSee('<svg', false);
});

it('keeps the same pending QR secret across page refreshes', function () {
    $admin = User::factory()->withRole('admin')->create();

    $first = $this->actingAs($admin)->get(route('mfa.setup'))->getContent();
    $second = $this->actingAs($admin)->get(route('mfa.setup'))->getContent();

    $secret = $admin->fresh()->mfaSecret->secret;
    expect($first)->toContain($secret)->and($second)->toContain($secret);
});

it('switches language and remembers it on the user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/')->post('/locale', ['locale' => 'si'])->assertRedirect('/');
    expect($user->fresh()->locale)->toBe('si');

    $this->actingAs($user->fresh())->get('/')->assertSee('උපකරණ පුවරුව');
    $this->actingAs($user)->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

it('serves the guest login page in the chosen language', function () {
    $this->withSession(['locale' => 'ta'])->get('/login')->assertSee('உள்நுழைக');
});
