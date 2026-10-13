<?php

use App\Modules\Communications\Database\Seeders\TemplateSeeder;
use App\Modules\Communications\Models\Consent;
use App\Modules\Communications\Models\Identity;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Services\ConsentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/** Posts a webhook body the way WhatsApp does: signed with the app secret over the exact bytes. */
function waPost(object $test, array $payload, ?string $secret = 'shh-secret', ?string $signatureOverride = null): TestResponse
{
    $body = json_encode($payload);
    $sig = $signatureOverride ?? ($secret === null ? null : 'sha256='.hash_hmac('sha256', $body, $secret));

    return $test->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($sig === null ? [] : ['HTTP_X_HUB_SIGNATURE_256' => $sig]), $body);
}

function waStatus(string $id, string $status, ?int $ts = null, array $extra = []): array
{
    return ['entry' => [['changes' => [['value' => ['statuses' => [['id' => $id, 'status' => $status, 'timestamp' => (string) ($ts ?? time()), 'recipient_id' => '94771234567'] + $extra]]]]]]];
}

function waIncoming(string $id, string $from, string $text, ?int $ts = null): array
{
    return ['entry' => [['changes' => [['value' => ['messages' => [['id' => $id, 'from' => $from, 'timestamp' => (string) ($ts ?? time()), 'type' => 'text', 'text' => ['body' => $text]]]]]]]]];
}

beforeEach(function () {
    config(['communications.whatsapp.app_secret' => 'shh-secret', 'communications.whatsapp.verify_token' => 'hello-word']);
    $this->sent = Message::query()->create(['direction' => 'out', 'channel' => 'whatsapp', 'status' => 'sent', 'address' => '94771234567', 'body' => 'x', 'provider_message_id' => 'wamid.OUT1', 'sent_at' => now()]);
});

describe('registering the webhook', function () {
    it('answers the check with the challenge only when the shared word matches', function () {
        $ok = $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=hello-word&hub.challenge=4711');
        $ok->assertOk();
        expect($ok->getContent())->toBe('4711')->and($ok->headers->get('Content-Type'))->toContain('text/plain');
        foreach (['hub.verify_token=wrong', 'hub.verify_token=', 'hub.mode=unsubscribe&hub.verify_token=hello-word'] as $query) {
            $this->getJson("/api/v1/webhooks/whatsapp?hub.challenge=1&{$query}")->assertForbidden()->assertJsonPath('error.code', 'comm.webhook_forbidden');
        }
        config(['communications.whatsapp.verify_token' => null]);
        $this->getJson('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
    });
});

describe('who may call', function () {
    it('accepts a correctly signed call and refuses everything else without reading it', function () {
        waPost($this, waStatus('wamid.OUT1', 'delivered'))->assertOk();
        expect($this->sent->refresh()->status)->toBe('delivered');

        $before = DB::table('inbox_events')->count();
        waPost($this, waStatus('wamid.OUT1', 'read'), 'wrong-secret')->assertUnauthorized()->assertJsonPath('error.code', 'comm.signature_invalid');
        waPost($this, waStatus('wamid.OUT1', 'read'), null)->assertUnauthorized();
        waPost($this, waStatus('wamid.OUT1', 'read'), 'shh-secret', 'sha256=')->assertUnauthorized();
        waPost($this, waStatus('wamid.OUT1', 'read'), 'shh-secret', hash_hmac('sha256', json_encode(waStatus('wamid.OUT1', 'read')), 'shh-secret'))->assertUnauthorized();   // no sha256= prefix
        expect($this->sent->refresh()->status)->toBe('delivered')->and(DB::table('inbox_events')->count())->toBe($before);
    });

    it('refuses a signature made for a different body', function () {
        $body = json_encode(waStatus('wamid.OUT1', 'read'));
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body.' ', 'shh-secret')], $body)->assertUnauthorized();
        expect($this->sent->refresh()->status)->toBe('sent');
    });

    it('is unavailable until the app secret is set, rather than trusting anyone', function () {
        config(['communications.whatsapp.app_secret' => null]);
        waPost($this, waStatus('wamid.OUT1', 'read'), '')->assertStatus(503)->assertJsonPath('error.code', 'comm.webhook_unconfigured');
        expect($this->sent->refresh()->status)->toBe('sent');
    });

    it('answers 200 to a call with nothing to do or a body that is not what we expect', function () {
        waPost($this, ['entry' => []])->assertOk();
        waPost($this, ['object' => 'whatsapp_business_account'])->assertOk();
        $raw = 'not json at all';
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh-secret')], $raw)->assertOk();
    });
});

describe('delivery reports', function () {
    it('move a message forward: sent, delivered, read, with the times WhatsApp gave', function () {
        $t = CarbonImmutable::now()->subMinutes(5)->getTimestamp();
        waPost($this, waStatus('wamid.OUT1', 'delivered', $t))->assertOk();
        $m = $this->sent->refresh();
        expect($m->status)->toBe('delivered')->and($m->delivered_at->getTimestamp())->toBe($t);
        waPost($this, waStatus('wamid.OUT1', 'read', $t + 60))->assertOk();
        expect($m->refresh()->status)->toBe('read')->and($m->read_at->getTimestamp())->toBe($t + 60);
    });

    it('never move one backwards, even when reports arrive in the wrong order', function () {
        waPost($this, waStatus('wamid.OUT1', 'read'))->assertOk();
        waPost($this, waStatus('wamid.OUT1', 'delivered'))->assertOk();
        waPost($this, waStatus('wamid.OUT1', 'sent'))->assertOk();
        $m = $this->sent->refresh();
        expect($m->status)->toBe('read')->and($m->delivered_at)->not->toBeNull()->and($m->read_at)->not->toBeNull();
    });

    it('record a failure with WhatsApp\'s reason, but not after the message was delivered', function () {
        waPost($this, waStatus('wamid.OUT1', 'failed', null, ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]))->assertOk();
        $m = $this->sent->refresh();
        expect($m->status)->toBe('failed')->and($m->error)->toBe('Message undeliverable')->and($m->failed_at)->not->toBeNull();

        $second = Message::query()->create(['direction' => 'out', 'channel' => 'whatsapp', 'status' => 'delivered', 'address' => '94771234567', 'body' => 'x', 'provider_message_id' => 'wamid.OUT2']);
        waPost($this, waStatus('wamid.OUT2', 'failed'))->assertOk();
        expect($second->refresh()->status)->toBe('delivered');
    });

    it('ignore a report for a message we did not send, and one with the same id and status twice', function () {
        waPost($this, waStatus('wamid.UNKNOWN', 'delivered'))->assertOk();
        expect(Message::count())->toBe(1);
        waPost($this, waStatus('wamid.OUT1', 'delivered'))->assertOk();
        $this->sent->refresh()->forceFill(['status' => 'sent', 'delivered_at' => null])->save();
        waPost($this, waStatus('wamid.OUT1', 'delivered'))->assertOk();                  // the same report again: recorded once, so nothing changes
        expect($this->sent->refresh()->status)->toBe('sent');
    });

    it('ignore anything older than a week, so an old call cannot be replayed into the present', function () {
        waPost($this, waStatus('wamid.OUT1', 'read', CarbonImmutable::now()->subDays(8)->getTimestamp()))->assertOk();
        expect($this->sent->refresh()->status)->toBe('sent');
    });
});

describe('incoming messages', function () {
    it('are kept, once, and linked to the user when the number is a linked one', function () {
        $user = userWithPermissions('comm.message.view');
        Identity::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'address' => '94771234567', 'verified_at' => now()]);
        waPost($this, waIncoming('wamid.IN1', '94771234567', 'Hello, is my order ready?'))->assertOk();
        waPost($this, waIncoming('wamid.IN1', '94771234567', 'Hello, is my order ready?'))->assertOk();
        $in = Message::where('direction', 'in')->get();
        expect($in)->toHaveCount(1)->and($in[0]->status)->toBe('received')->and($in[0]->body)->toBe('Hello, is my order ready?')->and($in[0]->party_type)->toBe('user')->and($in[0]->party_id)->toBe($user->id);
        waPost($this, waIncoming('wamid.IN2', '94112223333', 'who are you'))->assertOk();
        expect(Message::where('provider_message_id', 'wamid.IN2')->value('party_type'))->toBeNull();
    });

    it('cut a very long text and note what a non-text message was', function () {
        waPost($this, waIncoming('wamid.IN3', '94771234567', str_repeat('a', 9000)))->assertOk();
        expect(mb_strlen(Message::where('provider_message_id', 'wamid.IN3')->value('body')))->toBe(4000);
        $payload = ['entry' => [['changes' => [['value' => ['messages' => [['id' => 'wamid.IMG', 'from' => '94771234567', 'timestamp' => (string) time(), 'type' => 'image']]]]]]]];
        waPost($this, $payload)->assertOk();
        expect(Message::where('provider_message_id', 'wamid.IMG')->value('body'))->toBe('[image]');
    });

    it('opt the number out at once when the person writes a stop word, in any case or language', function () {
        $consents = app(ConsentService::class);
        $consents->record('whatsapp', '94771234567', 'opted_in', 'manual', 'ok');
        foreach (['STOP', ' Unsubscribe ', 'stop!', 'නවත්වන්න'] as $i => $word) {
            $consents->record('whatsapp', '94771234567', 'opted_in', 'manual', 'ok again');
            waPost($this, waIncoming("wamid.STOP{$i}", '94771234567', $word))->assertOk();
            expect($consents->status('whatsapp', '94771234567'))->toBe('opted_out', $word);
        }
        expect(Consent::where('source', 'keyword')->count())->toBe(4);
    });

    it('do not opt anyone out for an ordinary sentence that merely contains the word', function () {
        $consents = app(ConsentService::class);
        $consents->record('whatsapp', '94771234567', 'opted_in', 'manual', 'ok');
        foreach (['Please do not stop the delivery', 'stop by tomorrow', 'I cannot cancel my order please call me'] as $i => $text) {
            waPost($this, waIncoming("wamid.TALK{$i}", '94771234567', $text))->assertOk();
        }
        expect($consents->status('whatsapp', '94771234567'))->toBe('opted_in');
    });

    it('opt out a number we know nothing else about, so it is never written to', function () {
        waPost($this, waIncoming('wamid.NEW1', '94700000001', 'STOP'))->assertOk();
        expect(app(ConsentService::class)->status('whatsapp', '94700000001'))->toBe('opted_out');
    });
});

describe('the consent history', function () {
    it('can only be added to: the database refuses a change or a delete', function () {
        $c = app(ConsentService::class)->record('email', 'a@b.lk', 'opted_in', 'manual', 'ok');
        expect(fn () => inSavepoint(fn () => DB::table('comm_consents')->where('id', $c->id)->update(['status' => 'opted_out'])))->toThrow(QueryException::class, 'only ever added to');
        expect(fn () => inSavepoint(fn () => DB::table('comm_consents')->where('id', $c->id)->delete()))->toThrow(QueryException::class, 'only ever added to');
    });

    it('keeps who changed it and why, and cuts a very long note', function () {
        $staff = userWithPermissions('comm.consent.manage');
        $c = app(ConsentService::class)->record('email', 'a@b.lk', 'opted_out', 'manual', str_repeat('n', 400), $staff->id);
        expect($c->recorded_by)->toBe($staff->id)->and(mb_strlen((string) $c->note))->toBe(255);
    });

    it('lets the one-click e-mail link opt out, but only the signed link we sent', function () {
        $this->seed(TemplateSeeder::class);
        $m = Message::query()->create(['direction' => 'out', 'channel' => 'email', 'status' => 'sent', 'address' => 'buyer@lanka.test', 'body' => 'x', 'subject' => 's']);
        $this->get('/unsubscribe/'.$m->public_id)->assertForbidden();
        $this->get(URL::signedRoute('comm.unsubscribe', ['message' => $m->public_id]).'x')->assertForbidden();
        expect(app(ConsentService::class)->status('email', 'buyer@lanka.test'))->toBe('unknown');
        $url = URL::signedRoute('comm.unsubscribe', ['message' => $m->public_id]);
        $this->get($url)->assertOk()->assertSee('will not receive these e-mails any more');
        $this->get($url)->assertOk();
        expect(app(ConsentService::class)->status('email', 'buyer@lanka.test'))->toBe('opted_out')->and(Consent::where('source', 'unsubscribe_link')->count())->toBe(1);
        $this->get(URL::signedRoute('comm.unsubscribe', ['message' => '00000000-0000-0000-0000-000000000000']))->assertNotFound();
    });
});
