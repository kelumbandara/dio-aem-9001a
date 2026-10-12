<?php

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Contracts\Notifier;
use App\Modules\Core\DTOs\NotificationMessage;
use App\Modules\Core\Jobs\SendPush;
use App\Modules\Core\Models\MobileDevice;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function pushToken(string $suffix = 'AAAAAAAAAAAAAAAAAAAAAA'): string
{
    return "ExponentPushToken[{$suffix}]";
}

function registerBody(array $o = []): array
{
    return $o + ['push_token' => pushToken(), 'platform' => 'android', 'device_name' => 'Pixel 8', 'app_version' => '1.0.0', 'locale' => 'en'];
}

beforeEach(function () {
    config(['mobile.push.enabled' => true]);
    $this->user = userWithPermissions('approvals.view');
});

describe('the app settings', function () {
    it('tells the app the company, the modules that are on, and the oldest version accepted', function () {
        config(['mobile.min_app_version' => '1.2.0']);
        $json = asApi($this->user)->getJson('/api/v1/mobile/config')->assertOk()->json('data');
        expect($json['modules'])->toContain('core', 'sales')->and($json['min_app_version'])->toBe('1.2.0')->and($json['locales'])->toBe(['en', 'si', 'ta'])->and($json['company'])->toBeString();
        app(ModuleRegistry::class)->disable('manufacturing');
        expect(asApi($this->user)->getJson('/api/v1/mobile/config')->json('data.modules'))->not->toContain('manufacturing');
    });

    it('needs a sign-in', function () {
        $this->getJson('/api/v1/mobile/config')->assertUnauthorized();
        $this->postJson('/api/v1/devices', registerBody())->assertUnauthorized();
    });
});

describe('registering a phone', function () {
    it('saves the push address once, however often the app registers', function () {
        $id = asApi($this->user)->postJson('/api/v1/devices', registerBody())->assertCreated()->json('data.id');
        asApi($this->user)->postJson('/api/v1/devices', registerBody(['device_name' => 'Pixel 8 Pro', 'locale' => 'si']))->assertOk()->assertJsonPath('data.id', $id);
        expect(MobileDevice::count())->toBe(1)->and(MobileDevice::first()->device_name)->toBe('Pixel 8 Pro')->and(MobileDevice::first()->locale)->toBe('si')->and(MobileDevice::first()->user_id)->toBe($this->user->id);
    });

    it('refuses anything that is not an Expo push address, a known platform or language', function () {
        foreach ([['push_token' => 'abc'], ['push_token' => 'ExponentPushToken[]'], ['push_token' => 'ExponentPushToken[bad token with spaces]'], ['push_token' => "ExponentPushToken[AAAAAA\nAAAAAAAAAAAA]"],
            ['push_token' => 'https://evil.example/'], ['platform' => 'windows'], ['locale' => 'fr'], ['push_token' => str_repeat('A', 300)], ['device_name' => str_repeat('x', 121)]] as $bad) {
            asApi($this->user)->postJson('/api/v1/devices', registerBody($bad))->assertStatus(422);
        }
        asApi($this->user)->postJson('/api/v1/devices', ['platform' => 'ios'])->assertStatus(422);
        expect(MobileDevice::count())->toBe(0);
    });

    it('keeps only the newest few phones of a person', function () {
        config(['mobile.push.max_devices_per_user' => 2]);
        foreach (['AAAAAAAAAAAAAAAAAAAA01', 'AAAAAAAAAAAAAAAAAAAA02', 'AAAAAAAAAAAAAAAAAAAA03'] as $i => $t) {
            $this->travel($i + 1)->minutes();
            asApi($this->user)->postJson('/api/v1/devices', registerBody(['push_token' => pushToken($t)]))->assertSuccessful();
        }
        expect(MobileDevice::pluck('push_token')->all())->toBe([pushToken('AAAAAAAAAAAAAAAAAAAA02'), pushToken('AAAAAAAAAAAAAAAAAAAA03')]);
    });

    it('lets a person remove only their own phone', function () {
        $id = asApi($this->user)->postJson('/api/v1/devices', registerBody())->json('data.id');
        $other = userWithPermissions('approvals.view');
        asApi($other)->deleteJson("/api/v1/devices/{$id}")->assertNotFound();
        expect(MobileDevice::count())->toBe(1);
        asApi($this->user)->deleteJson("/api/v1/devices/{$id}")->assertNoContent();
        expect(MobileDevice::count())->toBe(0);
    });

    it('forgets the phone when that sign-in is signed out, and only that one', function () {
        $u = User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);
        $login = fn (string $device) => $this->postJson('/api/v1/auth/login', ['email' => 'amal@example.test', 'password' => 'secret-pass-1', 'device_name' => $device])->json('data.token');
        $phone = $login('phone');
        $tablet = $login('tablet');
        $this->withToken($phone)->postJson('/api/v1/devices', registerBody(['push_token' => pushToken('PHONEPHONEPHONEPHONE')]))->assertCreated();
        app('auth')->forgetGuards();
        $this->withToken($tablet)->postJson('/api/v1/devices', registerBody(['push_token' => pushToken('TABLETTABLETTABLETT')]))->assertCreated();
        expect(MobileDevice::count())->toBe(2);
        app('auth')->forgetGuards();
        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();
        expect(MobileDevice::pluck('push_token')->all())->toBe([pushToken('TABLETTABLETTABLETT')])->and(MobileDevice::first()->user_id)->toBe($u->id);
    });
});

describe('push messages', function () {
    $message = fn (string $doc = 'APR/1') => new NotificationMessage('approvals::notifications.requested', ['doc' => $doc, 'summary' => 'Purchase order for LKR 1,000', 'requester' => 'Amal'], '/approvals/abc', 'info', 'approval_request', 'abc');

    it('sends a notification to the person\'s phones in their own language, with where to open it', function () use ($message) {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'r1'], ['status' => 'ok', 'id' => 'r2']]])]);
        $this->user->forceFill(['locale' => 'en'])->save();
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken('ENGLISHENGLISHENGLISH'), 'platform' => 'android', 'locale' => 'en']);
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken('SINHALASINHALASINHALA'), 'platform' => 'ios', 'locale' => 'si']);

        app(Notifier::class)->notify([$this->user->id], $message());

        Http::assertSentCount(1);
        Http::assertSent(function ($r) {
            $m = $r->data();

            return $r->url() === 'https://exp.host/--/api/v2/push/send' && count($m) === 2 && $m[0]['to'] === pushToken('ENGLISHENGLISHENGLISH') && $m[1]['to'] === pushToken('SINHALASINHALASINHALA')
                && $m[0]['title'] !== $m[1]['title'] && str_contains($m[0]['title'], 'APR/1') && str_contains($m[0]['body'], 'Purchase order') && $m[0]['data']['url'] === '/approvals/abc' && $m[0]['data']['subject_id'] === 'abc'
                && $m[0]['priority'] === 'high' && ! array_key_exists('Authorization', $r->headers());
        });
    });

    it('sends nothing for people without a phone, for people switched off, or when push is off', function () use ($message) {
        Http::fake();
        app(Notifier::class)->notify([$this->user->id], $message());
        Http::assertNothingSent();
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken(), 'platform' => 'android']);
        config(['mobile.push.enabled' => false]);
        app(Notifier::class)->notify([$this->user->id], $message());
        Http::assertNothingSent();
        $inactive = User::factory()->inactive()->create();
        MobileDevice::create(['user_id' => $inactive->id, 'push_token' => pushToken('INACTIVEINACTIVEINACT'), 'platform' => 'android']);
        config(['mobile.push.enabled' => true]);
        app(Notifier::class)->notify([$inactive->id], $message());
        Http::assertNothingSent();
    });

    it('does not even queue a job for a person who has no phone registered', function () use ($message) {
        Queue::fake();
        app(Notifier::class)->notify([$this->user->id], $message());
        Queue::assertNothingPushed();
        expect($this->user->notifications()->count())->toBe(1);
    });

    it('queues the push for after the change is saved, not inside it', function () use ($message) {
        Queue::fake();
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken(), 'platform' => 'android']);
        app(Notifier::class)->notify([$this->user->id], $message());
        Queue::assertPushed(SendPush::class, fn ($job) => $job->userId === $this->user->id && $job->afterCommit === true);
    });

    it('sends the access token when one is configured, and never puts it in the message', function () use ($message) {
        config(['mobile.push.access_token' => 'expo-secret-token']);
        Http::fake(['*' => Http::response(['data' => [['status' => 'ok']]])]);
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken(), 'platform' => 'android']);
        app(Notifier::class)->notify([$this->user->id], $message());
        Http::assertSent(fn ($r) => $r->header('Authorization') === ['Bearer expo-secret-token'] && ! str_contains($r->body(), 'expo-secret-token'));
    });

    it('forgets a phone the push service says is gone, and keeps the others', function () use ($message) {
        Http::fake(['*' => Http::response(['data' => [['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']], ['status' => 'ok', 'id' => 'x']]])]);
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken('GONEGONEGONEGONEGONE'), 'platform' => 'android']);
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken('ALIVEALIVEALIVEALIVE'), 'platform' => 'android']);
        app(Notifier::class)->notify([$this->user->id], $message());
        expect(MobileDevice::pluck('push_token')->all())->toBe([pushToken('ALIVEALIVEALIVEALIVE')]);
    });

    it('lets the queue retry when the push service is down, and gives up quietly on a refused request', function () use ($message) {
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken(), 'platform' => 'android']);
        Http::fake(['*' => Http::sequence()->push('down', 503)->push(['errors' => [['code' => 'VALIDATION_ERROR']]], 400)]);
        expect(fn () => app(Notifier::class)->notify([$this->user->id], $message()))->toThrow(RuntimeException::class, 'status 503');
        app(Notifier::class)->notify([$this->user->id], $message());
        expect(MobileDevice::count())->toBe(1);
    });

    it('still writes the inbox entry when the push service fails to be reached in the queue', function () use ($message) {
        Queue::fake();
        MobileDevice::create(['user_id' => $this->user->id, 'push_token' => pushToken(), 'platform' => 'android']);
        app(Notifier::class)->notify([$this->user->id], $message());
        expect($this->user->notifications()->count())->toBe(1);
    });
});
