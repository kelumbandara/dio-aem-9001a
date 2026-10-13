<?php

use App\Modules\Communications\Database\Seeders\TemplateSeeder;
use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Models\Consent;
use App\Modules\Communications\Models\Identity;
use App\Modules\Communications\Models\IdentityChallenge;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;
use App\Modules\Communications\Services\ConsentService;
use App\Modules\Communications\Services\IdentityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** The six-digit code that was just "sent" to the number: read from what WhatsApp received. */
function codeSentToWhatsApp(): string
{
    $code = null;
    Http::assertSent(function ($r) use (&$code) {
        $p = $r['template']['components'][0]['parameters'][0]['text'] ?? null;
        $code = $p;

        return $p !== null;
    });

    return (string) $code;
}

function idFails(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (CommunicationsException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected communications error {$code}.");
}

beforeEach(function () {
    config(['communications.whatsapp.token' => 'wa-token', 'communications.whatsapp.phone_number_id' => '1234567890', 'mail.default' => 'array', 'mail.from.address' => 'erp@dio.test']);
    $this->seed(TemplateSeeder::class);
    Template::where('channel', 'whatsapp')->update(['approved' => true]);
    $this->waAnswer = ['status' => 200, 'body' => ['messages' => [['id' => 'wamid.OTP1']]]];
    Http::fake(['graph.facebook.com/*' => fn () => Http::response($this->waAnswer['body'], $this->waAnswer['status'])]);
    $this->user = userWithPermissions('comm.message.view');
    $this->svc = app(IdentityService::class);
});

describe('linking a phone number', function () {
    it('sends a code, accepts it once, links the number and records the person\'s permission', function () {
        $this->svc->start($this->user->id, 'whatsapp', '077 123 4567');
        $code = codeSentToWhatsApp();
        expect($code)->toMatch('/^\d{6}$/');
        $identity = $this->svc->confirm($this->user->id, 'whatsapp', $code);
        expect($identity->address)->toBe('94771234567')->and($identity->user_id)->toBe($this->user->id)->and($identity->verified_at)->not->toBeNull();
        expect(app(ConsentService::class)->status('whatsapp', '94771234567'))->toBe('opted_in')->and(Consent::where('source', 'identity_link')->count())->toBe(1)
            ->and(Consent::where('source', 'identity_link')->value('note'))->toContain('you consent to receive order updates and account notifications via WhatsApp');
        idFails(fn () => $this->svc->confirm($this->user->id, 'whatsapp', $code), 'comm.code_expired');         // a code works once
    });

    it('never keeps the code anywhere: only a fingerprint, and not in the message history', function () {
        $this->svc->start($this->user->id, 'whatsapp', '0771234567');
        $code = codeSentToWhatsApp();
        $c = IdentityChallenge::first();
        expect($c->code_hash)->toHaveLength(64)->and($c->code_hash)->not->toContain($code);
        $everything = json_encode([Message::all()->toArray(), IdentityChallenge::all()->toArray(), DB::table('audit_logs')->get()->toArray()]);
        expect($everything)->not->toContain($code);
        expect(Message::first()->body)->toContain('code itself is not kept')->and(Message::first()->template_key)->toBe('identity_code');
    });

    it('refuses a wrong code, counts the tries, and locks the code after five', function () {
        $this->svc->start($this->user->id, 'whatsapp', '0771234567');
        $code = codeSentToWhatsApp();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            idFails(fn () => $this->svc->confirm($this->user->id, 'whatsapp', $wrong), 'comm.code_invalid');
        }
        expect(IdentityChallenge::first()->attempts)->toBe(5);
        idFails(fn () => $this->svc->confirm($this->user->id, 'whatsapp', $code), 'comm.too_many_attempts');      // even the right code is refused now
        expect(Identity::count())->toBe(0);
    });

    it('expires the code after ten minutes', function () {
        $this->svc->start($this->user->id, 'whatsapp', '0771234567');
        $code = codeSentToWhatsApp();
        $this->travel(11)->minutes();
        idFails(fn () => $this->svc->confirm($this->user->id, 'whatsapp', $code), 'comm.code_expired');
    });

    it('lets only the newest code work', function () {
        $this->svc->start($this->user->id, 'whatsapp', '0771234567');
        $first = codeSentToWhatsApp();
        $this->svc->start($this->user->id, 'whatsapp', '0771234567');
        $codes = [];
        Http::assertSent(function ($r) use (&$codes) {
            $codes[] = $r['template']['components'][0]['parameters'][0]['text'];

            return true;
        });
        $second = end($codes);
        if ($first !== $second) {
            idFails(fn () => $this->svc->confirm($this->user->id, 'whatsapp', $first), 'comm.code_invalid');
        }
        expect($this->svc->confirm($this->user->id, 'whatsapp', $second)->address)->toBe('94771234567');
    });

    it('does not let one person confirm with another person\'s code', function () {
        $other = userWithPermissions('comm.message.view');
        $this->svc->start($this->user->id, 'whatsapp', '0771234567');
        $code = codeSentToWhatsApp();
        idFails(fn () => $this->svc->confirm($other->id, 'whatsapp', $code), 'comm.code_expired');
        expect(Identity::count())->toBe(0);
    });

    it('limits how many codes can be asked for in an hour', function () {
        for ($i = 0; $i < 3; $i++) {
            $this->svc->start($this->user->id, 'whatsapp', '07712345'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT));
        }
        idFails(fn () => $this->svc->start($this->user->id, 'whatsapp', '0771234599'), 'comm.too_many_codes');
        $this->travel(61)->minutes();
        $this->svc->start($this->user->id, 'whatsapp', '0771234599');
        expect(IdentityChallenge::count())->toBe(4);
    });

    it('lets a number belong to one person only', function () {
        $this->svc->start($this->user->id, 'whatsapp', '0771234567');
        $this->svc->confirm($this->user->id, 'whatsapp', codeSentToWhatsApp());
        $other = userWithPermissions('comm.message.view');
        idFails(fn () => $this->svc->start($other->id, 'whatsapp', '+94 77 123 4567'), 'comm.address_taken');
        idFails(fn () => $this->svc->start($this->user->id, 'whatsapp', '0771234567'), 'comm.already_linked');
    });

    it('does not send a code to a number that has opted out', function () {
        app(ConsentService::class)->record('whatsapp', '94771234567', 'opted_out', 'keyword', 'STOP');
        idFails(fn () => $this->svc->start($this->user->id, 'whatsapp', '0771234567'), 'comm.opted_out');
        Http::assertNothingSent();
    });

    it('refuses what cannot be linked: bad numbers, unknown channels, unset channels, unapproved templates', function () {
        idFails(fn () => $this->svc->start($this->user->id, 'whatsapp', '12'), 'comm.address_invalid');
        idFails(fn () => $this->svc->start($this->user->id, 'email', 'not-an-address'), 'comm.address_invalid');
        idFails(fn () => $this->svc->start($this->user->id, 'sms', '0771234567'), 'comm.channel_invalid');
        Template::where('key', 'identity_code')->where('channel', 'whatsapp')->update(['approved' => false]);
        idFails(fn () => $this->svc->start($this->user->id, 'whatsapp', '0771234567'), 'comm.template_unavailable');
        Template::where('key', 'identity_code')->where('channel', 'whatsapp')->update(['approved' => true]);
        config(['communications.whatsapp.token' => null]);
        idFails(fn () => $this->svc->start($this->user->id, 'whatsapp', '0771234567'), 'comm.channel_not_configured');
        Http::assertNothingSent();
        expect(IdentityChallenge::count())->toBe(0);
    });

    it('tells the person when the code could not be sent, and keeps nothing', function () {
        $this->waAnswer = ['status' => 503, 'body' => 'down'];
        idFails(fn () => $this->svc->start($this->user->id, 'whatsapp', '0771234567'), 'comm.send_failed');
        expect(IdentityChallenge::count())->toBe(0);
    });

    it('can be undone, by the owner of the link only', function () {
        $this->svc->start($this->user->id, 'whatsapp', '0771234567');
        $identity = $this->svc->confirm($this->user->id, 'whatsapp', codeSentToWhatsApp());
        $other = userWithPermissions('comm.message.view');
        expect(fn () => $this->svc->unlink($other->id, $identity->public_id))->toThrow(ModelNotFoundException::class);
        $this->svc->unlink($this->user->id, $identity->public_id);
        expect(Identity::count())->toBe(0);
    });
});

describe('linking an e-mail address', function () {
    it('works the same way, by e-mail', function () {
        $this->svc->start($this->user->id, 'email', ' Me@Company.TEST ');
        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->all()[0]->getOriginalMessage();
        expect($mail->getTo()[0]->getAddress())->toBe('me@company.test');
        preg_match('/\b(\d{6})\b/', $mail->getTextBody(), $m);
        $identity = $this->svc->confirm($this->user->id, 'email', $m[1]);
        expect($identity->address)->toBe('me@company.test')->and(app(ConsentService::class)->status('email', 'me@company.test'))->toBe('opted_in');
    });
});
