<?php

use App\Modules\Approvals\Database\Seeders\MessagePolicySeeder;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Communications\Database\Seeders\TemplateSeeder;
use App\Modules\Communications\Models\Identity;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;
use App\Modules\Communications\Services\ConsentService;
use App\Modules\Communications\Services\MessageService;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\MasterData\Actions\SaveCustomer;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['communications.whatsapp.token' => 'wa-token', 'communications.whatsapp.phone_number_id' => '1234567890', 'mail.default' => 'array', 'mail.from.address' => 'erp@dio.test']);
    $this->seed([TemplateSeeder::class, MessagePolicySeeder::class]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X1']]])]);
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders', 'email' => 'buyer@lanka.test', 'phone' => '077 123 4567']);
    $this->staff = userWithPermissions('comm.message.view', 'comm.message.draft', 'comm.message.send', 'comm.consent.manage', 'comm.template.manage');
    $this->viewer = userWithPermissions('comm.message.view');
    $this->nobody = userWithPermissions('sales.view');
    $this->body = ['channel' => 'email', 'template' => 'document_notice', 'party_type' => 'customer', 'party' => $this->customer->code, 'params' => ['document_type' => 'invoice', 'document_no' => 'INV/1']];
});

describe('signed-out and unauthorised visitors', function () {
    it('are sent to sign in, or refused', function () {
        $urls = ['/communications/messages', '/communications/templates', '/communications/consents', '/communications/channels'];
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->getJson('/api/v1/messages')->assertUnauthorized();
        foreach ($urls as $url) {
            $this->actingAs($this->nobody)->get($url)->assertStatus($url === '/communications/channels' ? 200 : 403);       // anyone signed in may link their own number
        }
        asApi($this->nobody)->getJson('/api/v1/messages')->assertForbidden();
        asApi($this->nobody)->postJson('/api/v1/messages', $this->body, idemKey())->assertForbidden();
        asApi($this->viewer)->postJson('/api/v1/messages', $this->body, idemKey())->assertForbidden();
    });
});

describe('the Messages screens', function () {
    it('prepare, show, send and cancel a message', function () {
        $this->actingAs($this->staff)->get('/communications/messages/create?channel=email&template=document_notice')->assertOk()->assertSee('document_type', false)->assertSee('document_no', false);
        $res = $this->actingAs($this->staff)->post('/communications/messages', $this->body);
        $m = Message::firstOrFail();
        $res->assertRedirect(route('comm.messages.show', $m->public_id));
        $this->actingAs($this->staff)->get(route('comm.messages.show', $m->public_id))->assertOk()->assertSee('Dear Lanka Traders')->assertSee('b•••@lanka.test')->assertSee('Send');
        $this->actingAs($this->staff)->post(route('comm.messages.send', $m->public_id))->assertRedirect();
        expect($m->refresh()->status)->toBe('sent');
        $this->actingAs($this->staff)->get('/communications/messages')->assertOk()->assertSee('b•••@lanka.test')->assertSee('Sent');
        $this->actingAs($this->staff)->get('/communications/messages?status=draft')->assertOk()->assertDontSee('b•••@lanka.test');
    });

    it('show the reason on the page when a message cannot be prepared or sent', function () {
        $this->actingAs($this->staff)->from('/communications/messages/create')->post('/communications/messages', ['party' => 'NO-SUCH'] + $this->body)->assertSessionHasErrors('party');
        $m = app(MessageService::class)->draft($this->body, $this->staff->id);
        app(ConsentService::class)->record('email', 'buyer@lanka.test', 'opted_out', 'manual', 'stop');
        $this->actingAs($this->staff)->post(route('comm.messages.send', $m->public_id))->assertSessionHasErrors('address');
        $this->actingAs($this->staff)->followingRedirects()->post(route('comm.messages.cancel', $m->public_id))->assertSee('cancelled');
    });

    it('let someone who may only read see messages but not send or cancel them', function () {
        $m = app(MessageService::class)->draft($this->body, $this->staff->id);
        $this->actingAs($this->viewer)->get(route('comm.messages.show', $m->public_id))->assertOk()->assertDontSee('Send</button>', false);
        $this->actingAs($this->viewer)->post(route('comm.messages.send', $m->public_id))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('comm.messages.cancel', $m->public_id))->assertForbidden();
        $this->actingAs($this->viewer)->get('/communications/messages/create')->assertForbidden();
    });

    it('hide an address in the middle everywhere it is listed', function () {
        app(MessageService::class)->draft($this->body, $this->staff->id);
        $page = $this->actingAs($this->staff)->get('/communications/messages')->assertOk()->getContent();
        expect($page)->not->toContain('buyer@lanka.test');
    });
});

describe('the API', function () {
    it('prepares, lists, shows, sends and cancels messages', function () {
        $id = asApi($this->staff)->postJson('/api/v1/messages', $this->body, idemKey())->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.to', 'b•••@lanka.test')->json('data.id');
        asApi($this->staff)->getJson('/api/v1/messages?filter[status]=draft')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->staff)->getJson("/api/v1/messages/{$id}")->assertOk()->assertJsonPath('data.body', fn ($b) => str_contains($b, 'Dear Lanka Traders'));
        asApi($this->staff)->postJson("/api/v1/messages/{$id}/send", [], idemKey())->assertOk()->assertJsonPath('data.status', 'queued');     // the answer is given before the queue has delivered it
        expect(Message::where('public_id', $id)->value('status'))->toBe('sent');
        $second = asApi($this->staff)->postJson('/api/v1/messages', $this->body, idemKey())->json('data.id');
        asApi($this->staff)->postJson("/api/v1/messages/{$second}/cancel", [], idemKey())->assertOk()->assertJsonPath('data.status', 'cancelled');
        asApi($this->staff)->getJson('/api/v1/messages?filter[status]=nonsense')->assertStatus(422);
    });

    it('answers errors in the standard envelope', function () {
        asApi($this->staff)->postJson('/api/v1/messages', ['party' => 'NO-SUCH'] + $this->body, idemKey())->assertStatus(422)->assertJsonPath('error.code', 'comm.party_not_found')->assertJsonPath('error.details.0.field', 'party');
        asApi($this->staff)->postJson('/api/v1/messages', ['channel' => 'sms'] + $this->body, idemKey())->assertStatus(422)->assertJsonPath('error.code', 'comm.channel_invalid');
        asApi($this->staff)->getJson('/api/v1/messages/00000000-0000-0000-0000-000000000000')->assertNotFound();
    });

    it('does not draft the same message twice for a repeated request', function () {
        $key = idemKey();
        asApi($this->staff)->postJson('/api/v1/messages', $this->body, $key)->assertCreated();
        asApi($this->staff)->postJson('/api/v1/messages', $this->body, $key)->assertCreated();
        expect(Message::count())->toBe(1);
    });

    it('is closed when the module is switched off', function () {
        app(ModuleRegistry::class)->disable('communications');
        asApi($this->staff)->getJson('/api/v1/messages')->assertForbidden();
        $this->actingAs($this->staff)->get('/communications/messages')->assertForbidden();
    });
});

describe('templates', function () {
    it('are listed to readers and edited only by those who manage them, within the rules', function () {
        $t = Template::where('key', 'payment_reminder')->where('channel', 'email')->first();
        asApi($this->viewer)->getJson('/api/v1/message-templates')->assertOk()->assertJsonFragment(['key' => 'payment_reminder', 'sensitive' => true]);
        asApi($this->viewer)->putJson("/api/v1/message-templates/{$t->public_id}", ['body' => 'Hello'], idemKey())->assertForbidden();
        asApi($this->staff)->putJson("/api/v1/message-templates/{$t->public_id}", ['body' => 'Dear {name}, {document_no} for {amount} is due on {due_date}.'])->assertOk();
        expect($t->refresh()->body)->toStartWith('Dear {name}, {document_no}');
        asApi($this->staff)->putJson("/api/v1/message-templates/{$t->public_id}", ['body' => 'Dear {name}, your {secret_field}.'])->assertStatus(422)->assertJsonPath('error.code', 'comm.placeholder_unknown');
        asApi($this->staff)->putJson("/api/v1/message-templates/{$t->public_id}", ['body' => str_repeat('x', 5000)])->assertStatus(422);
        expect($t->refresh()->body)->not->toContain('secret_field');
    });

    it('cannot be marked approved by WhatsApp without naming the template WhatsApp approved, and e-mail ignores WhatsApp fields', function () {
        $w = Template::where('key', 'payment_reminder')->where('channel', 'whatsapp')->first();
        $w->forceFill(['provider_template' => null])->save();
        asApi($this->staff)->putJson("/api/v1/message-templates/{$w->public_id}", ['approved' => true])->assertStatus(422)->assertJsonPath('error.code', 'comm.template_incomplete');
        asApi($this->staff)->putJson("/api/v1/message-templates/{$w->public_id}", ['approved' => true, 'provider_template' => 'payment_reminder_v2'])->assertOk();
        expect($w->refresh()->approved)->toBeTrue()->and($w->provider_template)->toBe('payment_reminder_v2');
        $e = Template::where('key', 'payment_reminder')->where('channel', 'email')->first();
        asApi($this->staff)->putJson("/api/v1/message-templates/{$e->public_id}", ['provider_template' => 'x', 'approved' => false])->assertOk();
        expect($e->refresh()->provider_template)->toBeNull()->and($e->approved)->toBeTrue();
    });

    it('can be edited on screen, the change is audited, and a bad value shows on the page', function () {
        $t = Template::where('key', 'document_notice')->where('channel', 'email')->first();
        $this->actingAs($this->staff)->get(route('comm.templates.edit', $t->public_id))->assertOk()->assertSee('{document_type}', false);
        $this->actingAs($this->staff)->put(route('comm.templates.update', $t->public_id), ['subject' => 'Hello {name}', 'body' => 'Your {document_type} is ready.', 'is_active' => '1'])->assertRedirect(route('comm.templates.index'));
        expect($t->refresh()->subject)->toBe('Hello {name}')->and($t->sensitive)->toBeFalse()->and(DB::table('audit_logs')->where('event', 'comm.template_changed')->count())->toBe(1);
        $this->actingAs($this->staff)->from('/x')->put(route('comm.templates.update', $t->public_id), ['body' => 'Your {bogus}.'])->assertSessionHasErrors('body');
        $this->actingAs($this->viewer)->get(route('comm.templates.edit', $t->public_id))->assertForbidden();
        $this->actingAs($this->viewer)->get('/communications/templates')->assertOk()->assertSee('Payment reminder')->assertDontSee('Edit</a>', false);
    });
});

describe('permission to contact', function () {
    it('can be looked up, recorded with a reason, and shows its history', function () {
        $this->actingAs($this->staff)->get('/communications/consents?channel=whatsapp&address=077+123+4567')->assertOk()->assertSee('+94771234567')->assertSee('nothing recorded');
        $this->actingAs($this->staff)->post('/communications/consents', ['channel' => 'whatsapp', 'address' => '077 123 4567', 'status' => 'opted_in', 'note' => 'Asked by phone on 4 Nov'])->assertRedirect();
        $this->actingAs($this->staff)->get('/communications/consents?channel=whatsapp&address=0771234567')->assertOk()->assertSee('has allowed contact')->assertSee('Asked by phone on 4 Nov')->assertSee('Entered by staff');
        $this->actingAs($this->staff)->get('/communications/consents?channel=email&address=nonsense')->assertOk()->assertSee('not a valid');
        $this->actingAs($this->staff)->from('/x')->post('/communications/consents', ['channel' => 'whatsapp', 'address' => '077 123 4567', 'status' => 'opted_out', 'note' => ''])->assertSessionHasErrors('note');
    });

    it('is recorded over the API only by those who manage it, and is hidden in the answer', function () {
        asApi($this->viewer)->postJson('/api/v1/consents', ['channel' => 'email', 'address' => 'buyer@lanka.test', 'status' => 'opted_out', 'note' => 'x'], idemKey())->assertForbidden();
        asApi($this->staff)->postJson('/api/v1/consents', ['channel' => 'email', 'address' => 'Buyer@Lanka.test', 'status' => 'opted_out', 'note' => 'asked to stop'], idemKey())->assertCreated()->assertJsonPath('data.status', 'opted_out')->assertJsonPath('data.address', 'b•••@lanka.test');
        asApi($this->viewer)->getJson('/api/v1/consents?channel=email&address=buyer@lanka.test')->assertOk()->assertJsonPath('data.status', 'opted_out')->assertJsonCount(1, 'data.history');
        asApi($this->staff)->postJson('/api/v1/consents', ['channel' => 'email', 'address' => 'bad', 'status' => 'opted_in', 'note' => 'x'], idemKey())->assertStatus(422);
    });
});

describe('my phone and e-mail', function () {
    it('link a number through the screens and the API, and show it hidden', function () {
        $this->actingAs($this->nobody)->from('/communications/channels')->post('/communications/channels', ['channel' => 'whatsapp', 'address' => '077 123 4567'])->assertSessionHasErrors('template');   // WhatsApp has not approved the code message yet
        Template::where('channel', 'whatsapp')->update(['approved' => true]);
        $this->actingAs($this->nobody)->post('/communications/channels', ['channel' => 'whatsapp', 'address' => '077 123 4567'])->assertRedirect(route('comm.channels.index'));
        $code = null;
        Http::assertSent(function ($r) use (&$code) {
            $code = $r['template']['components'][0]['parameters'][0]['text'];

            return true;
        });
        $this->actingAs($this->nobody)->followingRedirects()->post('/communications/channels/confirm', ['channel' => 'whatsapp', 'code' => $code === '000000' ? '111111' : '000000'])->assertSee('not right');
        $this->actingAs($this->nobody)->post('/communications/channels/confirm', ['channel' => 'whatsapp', 'code' => $code])->assertRedirect(route('comm.channels.index'));
        $this->actingAs($this->nobody)->get('/communications/channels')->assertOk()->assertSee('94••••••567')->assertDontSee('94771234567');
        asApi($this->nobody)->getJson('/api/v1/me/channels')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.address', '94••••••567');
        $id = Identity::firstOrFail()->public_id;
        asApi(userWithPermissions('sales.view'))->deleteJson("/api/v1/me/channels/{$id}")->assertNotFound();
        asApi($this->nobody)->deleteJson("/api/v1/me/channels/{$id}")->assertNoContent();
        expect(Identity::count())->toBe(0);
    });

    it('show an unusable number as a message on the page', function () {
        $this->actingAs($this->nobody)->from('/communications/channels')->post('/communications/channels', ['channel' => 'whatsapp', 'address' => '12'])->assertSessionHasErrors('address');
        asApi($this->nobody)->postJson('/api/v1/me/channels', ['channel' => 'whatsapp', 'address' => '12'])->assertStatus(422)->assertJsonPath('error.code', 'comm.address_invalid');
    });
});

describe('who gets what', function () {
    it('gives the business roles the messages they need, and machine operators nothing', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $has = fn (string $role, string $permission) => Role::findByName($role, 'web')->hasPermissionTo($permission);
        expect($has('accountant', 'comm.message.send'))->toBeTrue()->and($has('accountant', 'comm.consent.manage'))->toBeTrue()->and($has('accountant', 'comm.template.manage'))->toBeFalse()
            ->and($has('sales', 'comm.message.draft'))->toBeTrue()->and($has('sales', 'comm.message.send'))->toBeFalse()->and($has('purchasing', 'comm.message.draft'))->toBeTrue()
            ->and($has('admin', 'comm.template.manage'))->toBeTrue()->and($has('operator', 'comm.message.view'))->toBeFalse()->and($has('production', 'comm.message.view'))->toBeFalse();
    });

    it('starts with an approval rule for sensitive messages that the seeder never overwrites', function () {
        $policy = ApprovalPolicy::where('action_key', 'comm.message.send')->firstOrFail();
        $policy->forceFill(['is_active' => false])->save();
        $this->seed(MessagePolicySeeder::class);
        expect(ApprovalPolicy::where('action_key', 'comm.message.send')->count())->toBe(1)->and($policy->refresh()->is_active)->toBeFalse();
    });
});
