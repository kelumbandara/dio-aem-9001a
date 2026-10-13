<?php

use App\Modules\Approvals\Database\Seeders\MessagePolicySeeder;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Communications\Contracts\MessageDrafter;
use App\Modules\Communications\Contracts\Messenger;
use App\Modules\Communications\Database\Seeders\TemplateSeeder;
use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Jobs\DeliverMessage;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;
use App\Modules\Communications\Services\ConsentService;
use App\Modules\Communications\Services\MessageService;
use App\Modules\Core\Models\AuditLog;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function commFails(Closure $fn, string $code): CommunicationsException
{
    try {
        $fn();
    } catch (CommunicationsException $e) {
        expect($e->errorCode)->toBe($code);

        return $e;
    }
    test()->fail("Expected communications error {$code}.");
}

/** The e-mails the array mailer "sent" in this test. */
function commMails(): array
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
}

beforeEach(function () {
    config([
        'communications.whatsapp.token' => 'wa-token-secret', 'communications.whatsapp.phone_number_id' => '1234567890', 'communications.whatsapp.app_secret' => 'shh-secret',
        'mail.default' => 'array', 'mail.from.address' => 'erp@dio.test', 'communications.max_per_address_per_day' => 5,
    ]);
    $this->seed([TemplateSeeder::class, MessagePolicySeeder::class]);
    Template::where('channel', 'whatsapp')->update(['approved' => true]);
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders', 'email' => 'Buyer@Lanka.test', 'phone' => '077 123 4567']);
    $this->staff = userWithPermissions('comm.message.view', 'comm.message.draft', 'comm.message.send', 'comm.consent.manage');
    $this->svc = app(MessageService::class);
    $this->consents = app(ConsentService::class);
    $this->notice = fn (string $channel = 'email', array $o = []) => $this->svc->draft($o + [
        'channel' => $channel, 'template' => 'document_notice', 'party_type' => 'customer', 'party' => $this->customer->code, 'params' => ['document_type' => 'invoice', 'document_no' => 'INV/2026-27/00001'],
    ], $this->staff->id);
    $this->reminder = fn (string $channel = 'email') => $this->svc->draft([
        'channel' => $channel, 'template' => 'payment_reminder', 'party_type' => 'customer', 'party' => $this->customer->code, 'params' => ['document_no' => 'INV/1', 'amount' => 'LKR 25,000.00', 'due_date' => '2026-11-30'],
    ], $this->staff->id);
    // what WhatsApp answers: a test changes this to make it busy, refuse or vanish
    $this->waAnswer = ['status' => 200, 'body' => ['messages' => [['id' => 'wamid.ABC123']]]];
    Http::fake(['graph.facebook.com/*' => fn () => $this->waAnswer instanceof Closure ? ($this->waAnswer)() : Http::response($this->waAnswer['body'], $this->waAnswer['status'])]);
});

describe('preparing a message', function () {
    it('finds the person\'s address on file, fills the template and keeps it as a draft', function () {
        $m = ($this->notice)();
        expect($m->status)->toBe('draft')->and($m->address)->toBe('buyer@lanka.test')->and($m->party_type)->toBe('customer')->and($m->party_id)->toBe($this->customer->id)
            ->and($m->subject)->toStartWith('Your invoice INV/2026-27/00001 from ')->and($m->body)->toContain('Dear Lanka Traders')->and($m->requested_by)->toBe($this->staff->id)->and($m->drafted_by_ai)->toBeFalse();
        expect(commMails())->toBe([]);
        $w = ($this->notice)('whatsapp');
        expect($w->address)->toBe('94771234567')->and($w->channel)->toBe('whatsapp');
    });

    it('refuses what cannot be prepared, saying why and on which field', function () {
        $this->customer->forceFill(['email' => null])->save();
        $cases = [
            [fn () => ($this->notice)('sms'), 'comm.channel_invalid'],
            [fn () => ($this->notice)('email'), 'comm.no_address'],
            [fn () => ($this->notice)('whatsapp', ['party' => 'NO-SUCH']), 'comm.party_not_found'],
            [fn () => ($this->notice)('whatsapp', ['party_type' => 'staff']), 'comm.recipient_invalid'],
            [fn () => ($this->notice)('whatsapp', ['template' => 'no_such_template']), 'comm.template_unavailable'],
            [fn () => ($this->notice)('whatsapp', ['params' => ['document_no' => 'X']]), 'comm.param_missing'],
            [fn () => $this->svc->draft(['channel' => 'email', 'template' => 'document_notice'], $this->staff->id), 'comm.recipient_required'],
            [fn () => $this->svc->draft(['channel' => 'email', 'template' => 'document_notice', 'address' => 'not an address', 'params' => ['document_type' => 'a', 'document_no' => 'b']], $this->staff->id), 'comm.address_invalid'],
        ];
        foreach ($cases as [$run, $code]) {
            commFails($run, $code);
        }
        expect(Message::count())->toBe(0);
    });

    it('will not use a WhatsApp template that WhatsApp has not approved', function () {
        Template::where('key', 'document_notice')->where('channel', 'whatsapp')->update(['approved' => false]);
        commFails(fn () => ($this->notice)('whatsapp'), 'comm.template_unavailable');
        Template::where('key', 'document_notice')->where('channel', 'whatsapp')->update(['approved' => true, 'is_active' => false]);
        commFails(fn () => ($this->notice)('whatsapp'), 'comm.template_unavailable');
    });

    it('takes a plain address for someone who is not a customer or supplier, and cleans what is put into the message', function () {
        $m = $this->svc->draft(['channel' => 'email', 'template' => 'document_notice', 'address' => ' Joe@Example.TEST ', 'params' => ['document_type' => "quote\r\nBcc: x@y.z", 'document_no' => 'Q1']], $this->staff->id);
        expect($m->address)->toBe('joe@example.test')->and($m->party_type)->toBeNull()->and($m->body)->not->toContain("\r")->and($m->body)->toContain('quote Bcc: x@y.z');
    });

    it('falls back to English when there is no template in the language asked for', function () {
        expect(($this->notice)('email', ['locale' => 'si'])->locale)->toBe('en');
    });
});

describe('sending', function () {
    it('queues a draft and delivers an e-mail exactly once, with the link to stop them', function () {
        $m = $this->svc->send(($this->notice)(), $this->staff->id);
        $m->refresh();
        expect($m->status)->toBe('sent')->and($m->sent_at)->not->toBeNull()->and($m->attempts)->toBe(1)->and($m->provider_message_id)->toBe($m->public_id);
        $sent = commMails();
        expect($sent)->toHaveCount(1);
        $mail = $sent[0]->getOriginalMessage();
        expect($mail->getTo()[0]->getAddress())->toBe('buyer@lanka.test')->and($mail->getSubject())->toContain('INV/2026-27/00001')->and($mail->getTextBody())->toContain('Dear Lanka Traders')->and($mail->getTextBody())->toContain('/unsubscribe/'.$m->public_id);
        expect(AuditLog::where('event', 'comm.message_sent')->count())->toBe(1);
        (new DeliverMessage($m->id))->handle($this->svc);                                  // the queue delivering the same message again
        expect(commMails())->toHaveCount(1);                                               // changes nothing
    });

    it('sends WhatsApp as the template Meta approved, with the values in Meta\'s order, once the person has allowed it', function () {
        $m = ($this->notice)('whatsapp');
        commFails(fn () => $this->svc->send($m, $this->staff->id), 'comm.no_consent');
        $this->consents->record('whatsapp', '94771234567', 'opted_in', 'manual', 'Asked by phone', $this->staff->id);
        $sent = $this->svc->send($m, $this->staff->id)->refresh();
        expect($sent->status)->toBe('sent')->and($sent->provider_message_id)->toBe('wamid.ABC123');
        Http::assertSent(function ($r) {
            $t = $r['template'];

            return $r->url() === 'https://graph.facebook.com/v21.0/1234567890/messages' && $r->header('Authorization') === ['Bearer wa-token-secret'] && $r['to'] === '94771234567' && $r['type'] === 'template'
                && $t['name'] === 'document_notice' && $t['language']['code'] === 'en'
                && array_slice(array_column($t['components'][0]['parameters'], 'text'), 0, 3) === ['Lanka Traders', 'invoice', 'INV/2026-27/00001'] && $t['components'][0]['parameters'][3]['text'] !== ''
                && ! str_contains($r->body(), 'wa-token-secret');
        });
    });

    it('refuses an address that has opted out, at once, on that channel only', function () {
        $this->consents->record('email', 'buyer@lanka.test', 'opted_out', 'manual', 'Asked to stop', $this->staff->id);
        commFails(fn () => $this->svc->send(($this->notice)(), $this->staff->id), 'comm.opted_out');
        expect(commMails())->toBe([]);
        $this->consents->record('whatsapp', '94771234567', 'opted_in', 'manual', 'ok', $this->staff->id);
        expect($this->svc->send(($this->notice)('whatsapp'), $this->staff->id)->refresh()->status)->toBe('sent');
    });

    it('honours an opt-out that arrives while the message waits in the queue', function () {
        Queue::fake();
        $m = $this->svc->send(($this->notice)(), $this->staff->id);
        expect($m->status)->toBe('queued');
        $this->consents->record('email', 'buyer@lanka.test', 'opted_out', 'keyword', 'Replied stop');
        $this->svc->deliver($m->id);
        $m->refresh();
        expect($m->status)->toBe('cancelled')->and($m->error_code)->toBe('comm.opted_out')->and(commMails())->toBe([]);
    });

    it('lets a later opt-in lift an opt-out, and keeps the whole history', function () {
        $this->consents->record('email', 'buyer@lanka.test', 'opted_out', 'manual', 'stop');
        $this->consents->record('email', 'buyer@lanka.test', 'opted_in', 'manual', 'customer asked to be added again', $this->staff->id);
        expect($this->consents->status('email', 'buyer@lanka.test'))->toBe('opted_in')->and($this->consents->history('email', 'buyer@lanka.test'))->toHaveCount(2);
        expect($this->svc->send(($this->notice)(), $this->staff->id)->refresh()->status)->toBe('sent');
    });

    it('stops too many messages going to one address in a day', function () {
        config(['communications.max_per_address_per_day' => 2]);
        $this->svc->send(($this->notice)(), $this->staff->id);
        $this->svc->send(($this->notice)(), $this->staff->id);
        commFails(fn () => $this->svc->send(($this->notice)(), $this->staff->id), 'comm.too_many');
        expect(commMails())->toHaveCount(2);
        $this->travel(25)->hours();
        expect($this->svc->send(($this->notice)(), $this->staff->id)->refresh()->status)->toBe('sent');
    });

    it('says plainly when the channel is not set up, and sends nothing', function () {
        config(['communications.whatsapp.token' => null]);
        $this->consents->record('whatsapp', '94771234567', 'opted_in', 'manual', 'ok');
        commFails(fn () => $this->svc->send(($this->notice)('whatsapp'), $this->staff->id), 'comm.channel_not_configured');
        Http::assertNothingSent();
    });

    it('only sends a draft or a failed message', function () {
        $m = $this->svc->send(($this->notice)(), $this->staff->id)->refresh();
        commFails(fn () => $this->svc->send($m, $this->staff->id), 'comm.invalid_state');
        commFails(fn () => $this->svc->cancel($m, $this->staff->id), 'comm.invalid_state');
    });

    it('can cancel a draft, which is then never sent', function () {
        $m = ($this->notice)();
        expect($this->svc->cancel($m, $this->staff->id)->status)->toBe('cancelled');
        commFails(fn () => $this->svc->send($m->refresh(), $this->staff->id), 'comm.invalid_state');
    });
});

describe('when delivery goes wrong', function () {
    beforeEach(fn () => $this->consents->record('whatsapp', '94771234567', 'opted_in', 'manual', 'ok'));

    it('queues it again after a busy answer, and gives up after the allowed number of tries', function () {
        $this->waAnswer = ['status' => 503, 'body' => 'busy'];
        Queue::fake();
        $m = $this->svc->send(($this->notice)('whatsapp'), $this->staff->id);
        for ($i = 1; $i <= 3; $i++) {
            $retry = $this->svc->deliver($m->id);
            $m->refresh();
            expect($m->attempts)->toBe($i)->and($retry)->toBe($i < 3)->and($m->status)->toBe($i < 3 ? 'queued' : 'failed');
        }
        expect($m->error_code)->toBe('comm.whatsapp_busy')->and($m->failed_at)->not->toBeNull();
        expect($this->svc->deliver($m->id))->toBeFalse();                                  // a failed message is not touched by the queue again
    });

    it('fails at once, without retrying, when WhatsApp refuses the message, and a person can try again', function () {
        $this->waAnswer = ['status' => 400, 'body' => ['error' => ['message' => 'Template wa-token-secret not found', 'code' => 132001]]];
        Queue::fake();
        $m = $this->svc->send(($this->notice)('whatsapp'), $this->staff->id);
        expect($this->svc->deliver($m->id))->toBeFalse();
        $m->refresh();
        expect($m->status)->toBe('failed')->and($m->attempts)->toBe(1)->and($m->error)->toContain('refused')->and($m->error)->not->toContain('wa-token-secret')->and($m->error)->toContain('[token]');
        $this->waAnswer = ['status' => 200, 'body' => ['messages' => [['id' => 'wamid.RETRY']]]];
        expect($this->svc->send($m->refresh(), $this->staff->id)->refresh()->status)->toBe('queued');
    });

    it('treats a lost connection as worth trying again', function () {
        $this->waAnswer = fn () => throw new ConnectionException('timeout');
        Queue::fake();
        $m = $this->svc->send(($this->notice)('whatsapp'), $this->staff->id);
        expect($this->svc->deliver($m->id))->toBeTrue();
        expect($m->refresh()->status)->toBe('queued')->and($m->error_code)->toBe('comm.whatsapp_unreachable');
    });

    it('marks a message failed when the queue itself gives up', function () {
        Queue::fake();
        $m = $this->svc->send(($this->notice)('whatsapp'), $this->staff->id);
        (new DeliverMessage($m->id))->failed(new RuntimeException('gone'));
        expect($m->refresh()->status)->toBe('failed')->and($m->error_code)->toBe('comm.gave_up');
    });

    it('never sends two at once: a message being sent is left alone', function () {
        Queue::fake();
        $m = $this->svc->send(($this->notice)('whatsapp'), $this->staff->id);
        $m->forceFill(['status' => 'sending'])->save();
        expect($this->svc->deliver($m->id))->toBeFalse();
        Http::assertNothingSent();
    });
});

describe('sensitive messages', function () {
    beforeEach(function () {
        $this->approver = userWithRole('finance-approver');
        $this->engine = app(ApprovalEngine::class);
    });

    it('wait for approval, are checked again when approved, and only then are sent', function () {
        $m = $this->svc->send(($this->reminder)(), $this->staff->id);
        expect($m->status)->toBe('awaiting_approval')->and($m->approval_request_id)->not->toBeNull();
        expect(commMails())->toBe([]);
        $this->engine->approve($m->approval_request_id, $this->approver->id);
        expect($m->refresh()->status)->toBe('sent')->and(commMails())->toHaveCount(1);
    });

    it('are stopped by an opt-out that comes between the request and the approval', function () {
        $m = $this->svc->send(($this->reminder)(), $this->staff->id);
        $this->consents->record('email', 'buyer@lanka.test', 'opted_out', 'manual', 'asked to stop');
        $this->engine->approve($m->approval_request_id, $this->approver->id);
        expect($m->refresh()->status)->toBe('awaiting_approval')->and(commMails())->toBe([]);
        expect(DB::table('approval_requests')->where('public_id', $m->approval_request_id)->value('status'))->toBe('execution_failed');
    });

    it('cannot be approved after they were cancelled', function () {
        $m = $this->svc->send(($this->reminder)(), $this->staff->id);
        $this->svc->cancel($m, $this->staff->id);
        $this->engine->approve($m->approval_request_id, $this->approver->id);
        expect($m->refresh()->status)->toBe('cancelled')->and(commMails())->toBe([]);
    });

    it('need nobody\'s approval when the template is not marked sensitive, and always do once it is', function () {
        expect($this->svc->send(($this->notice)(), $this->staff->id)->refresh()->status)->toBe('sent');
        Template::where('key', 'document_notice')->where('channel', 'email')->update(['sensitive' => true]);
        expect($this->svc->send(($this->notice)(), $this->staff->id)->status)->toBe('awaiting_approval');
    });

    it('cannot be approved by the person who asked for them', function () {
        $boss = userWithRole('finance-approver');
        $m = $this->svc->send($this->svc->draft(['channel' => 'email', 'template' => 'payment_reminder', 'party_type' => 'customer', 'party' => $this->customer->code,
            'params' => ['document_no' => 'INV/9', 'amount' => 'LKR 1.00', 'due_date' => '2026-12-01']], $boss->id), $boss->id);
        expect(fn () => $this->engine->approve($m->approval_request_id, $boss->id))->toThrow(BusinessException::class);
        expect($m->refresh()->status)->toBe('awaiting_approval');
    });
});

describe('what other modules and the AI may do', function () {
    it('lets another module send through the same checks', function () {
        $id = app(Messenger::class)->send(['channel' => 'email', 'template' => 'document_notice', 'party_type' => 'customer', 'party' => $this->customer->code,
            'params' => ['document_type' => 'order', 'document_no' => 'SO/1']], $this->staff->id);
        expect(Message::where('public_id', $id)->value('status'))->toBe('sent');
        $this->consents->record('email', 'buyer@lanka.test', 'opted_out', 'manual', 'stop');
        commFails(fn () => app(Messenger::class)->send(['channel' => 'email', 'template' => 'document_notice', 'party_type' => 'customer', 'party' => $this->customer->code,
            'params' => ['document_type' => 'order', 'document_no' => 'SO/2']], $this->staff->id), 'comm.opted_out');
    });

    it('lets the assistant prepare a draft, marked as its own, but never send it, and only for someone with the permission', function () {
        $drafter = app(MessageDrafter::class);
        $d = $drafter->draft($this->staff, $this->staff->id, ['party_type' => 'customer', 'party' => $this->customer->code, 'channel' => 'email', 'template' => 'document_notice',
            'params' => ['document_type' => 'invoice', 'document_no' => 'INV/5'], 'status' => 'sent', 'body' => 'Send me money', 'address' => 'evil@x.test', 'requested_by' => 999]);
        $m = Message::where('public_id', $d['id'])->first();
        expect($d['status'])->toBe('draft')->and($d['to'])->toBe('b•••@lanka.test')->and($m->drafted_by_ai)->toBeTrue()->and($m->status)->toBe('draft')->and($m->address)->toBe('buyer@lanka.test')
            ->and($m->body)->not->toContain('Send me money')->and($m->requested_by)->toBe($this->staff->id);
        expect(commMails())->toBe([]);
        $viewer = userWithPermissions('comm.message.view');
        commFails(fn () => $drafter->draft($viewer, $viewer->id, ['party_type' => 'customer', 'party' => $this->customer->code, 'channel' => 'email', 'template' => 'document_notice', 'params' => ['document_type' => 'a', 'document_no' => 'b']]), 'comm.forbidden');
    });
});
