<?php

use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** A mail system that refuses every message, like a provider that rejects an address (a reserved domain, a mailbox that does not exist). */
beforeEach(function () {
    Mail::extend('refusing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('554 5.7.1 Recipient domain is reserved and cannot receive mail');
        }

        public function __toString(): string
        {
            return 'refusing';
        }
    });
    config(['mail.default' => 'refusing', 'mail.mailers.refusing' => ['transport' => 'refusing'], 'core.auth.check_breached' => false]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = adminUser('admin');
});

it('does not create the user, and says why on the form, when the e-mail is refused', function () {
    $this->actingAs($this->admin)->post('/admin/users', ['name' => 'Restore Test', 'email' => 'restore@example.com', 'roles' => ['sales']])
        ->assertSessionHasErrors('email')->assertRedirect();

    expect(User::where('email', 'restore@example.com')->exists())->toBeFalse();
});

it('says so, instead of failing, when a password link cannot be sent to an existing person', function () {
    $person = User::factory()->withRole('sales')->create(['email' => 'nimal@example.com']);

    $this->actingAs($this->admin)->post("/admin/users/{$person->public_id}/password-link")->assertSessionHasErrors('form')->assertRedirect();
});

it('gives "forgot password" the same quiet answer when the mail is refused, so it cannot reveal which addresses have accounts', function () {
    User::factory()->withRole('sales')->create(['email' => 'nimal@example.com']);

    $known = $this->post('/forgot-password', ['email' => 'nimal@example.com'])->assertSessionHas('status')->getSession()->get('status');
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status')->getSession()->get('status');

    expect($known)->toBe($unknown);
});
