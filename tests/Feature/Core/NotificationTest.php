<?php

use App\Modules\Core\Contracts\Notifier;
use App\Modules\Core\DTOs\NotificationMessage;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function message(string $doc = 'APR/1', string $url = '/approvals/x'): NotificationMessage
{
    return new NotificationMessage('approvals::notifications.requested', ['doc' => $doc, 'summary' => 'Pay ACME', 'requester' => 'Nimal'], $url, 'warning');
}

it('delivers to active internal users once, skipping everyone else', function () {
    $active = User::factory()->create();
    $inactive = User::factory()->inactive()->create();
    $customer = User::factory()->create(['user_type' => User::TYPE_CUSTOMER]);

    app(Notifier::class)->notify([$active->id, $active->id, $inactive->id, $customer->id, 99999], message());

    expect($active->notifications)->toHaveCount(1)->and($inactive->notifications)->toHaveCount(0)->and($customer->notifications)->toHaveCount(0);
});

it('writes in the caller\'s transaction so a rollback leaves no notification', function () {
    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user) {
            app(Notifier::class)->notify([$user->id], message());
            throw new RuntimeException('business failure');
        });
    } catch (RuntimeException) {
    }

    expect($user->notifications()->count())->toBe(0);
});

it('shows each reader the notification in their own language', function () {
    $english = User::factory()->create(['locale' => 'en']);
    $sinhala = User::factory()->create(['locale' => 'si']);
    $tamil = User::factory()->create(['locale' => 'ta']);
    app(Notifier::class)->notify([$english->id, $sinhala->id, $tamil->id], message('APR/9'));

    $this->actingAs($english)->get('/notifications')->assertOk()->assertSee('Approval needed: APR/9')->assertDontSee('අනුමැතිය අවශ්‍යයි');
    $this->actingAs($sinhala->fresh())->get('/notifications')->assertSee('අනුමැතිය අවශ්‍යයි: APR/9');
    $this->actingAs($tamil->fresh())->get('/notifications')->assertSee('ஒப்புதல் தேவை: APR/9');
});

it('falls back to the key when a translation is missing, so the gap is visible', function () {
    $user = User::factory()->create();
    app(Notifier::class)->notify([$user->id], new NotificationMessage('nowhere::missing.thing'));

    $this->actingAs($user)->get('/notifications')->assertSee('nowhere::missing.thing.title');
});

it('shows an unread count in the header and clears it when read', function () {
    $user = User::factory()->create();
    app(Notifier::class)->notify([$user->id], message());
    app(Notifier::class)->notify([$user->id], message('APR/2'));

    $this->actingAs($user)->get('/')->assertSee('>2<', false);

    $this->actingAs($user)->post('/notifications/read-all')->assertRedirect();
    $this->actingAs($user)->get('/')->assertDontSee('>2<', false);
});

it('marks one notification read and follows its link, but only to a local path', function () {
    $user = User::factory()->create();
    app(Notifier::class)->notify([$user->id], message('APR/1', '/approvals/abc'));
    app(Notifier::class)->notify([$user->id], message('APR/2', 'https://evil.example/phish'));
    app(Notifier::class)->notify([$user->id], message('APR/3', '//evil.example/phish'));

    [$a, $b, $c] = $user->notifications()->orderBy('created_at')->orderBy('id')->get()->sortBy(fn ($n) => $n->data['params']['doc'])->values()->all();

    $this->actingAs($user)->post("/notifications/{$a->id}/read")->assertRedirect('/approvals/abc');
    $this->actingAs($user)->post("/notifications/{$b->id}/read")->assertRedirect(route('notifications.index'));
    $this->actingAs($user)->post("/notifications/{$c->id}/read")->assertRedirect(route('notifications.index'));
    expect($user->unreadNotifications()->count())->toBe(0);
});

it('does not let one user read another\'s notifications', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    app(Notifier::class)->notify([$owner->id], message());
    $id = $owner->notifications()->first()->id;

    $this->actingAs($other)->post("/notifications/{$id}/read")->assertNotFound();
    Sanctum::actingAs($other);
    $this->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    expect($owner->unreadNotifications()->count())->toBe(1);
});

describe('API', function () {
    it('lists, filters unread, reports the unread total and marks read', function () {
        $user = User::factory()->create(['locale' => 'si']);
        foreach (range(1, 3) as $i) {
            app(Notifier::class)->notify([$user->id], message("APR/{$i}"));
        }
        Sanctum::actingAs($user);

        // The test client sends a default Accept-Language; an app without a preference sends none.
        $all = $this->getJson('/api/v1/notifications', ['Accept-Language' => ''])->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.unread', 3)
            ->assertJsonStructure(['data' => [['id', 'title', 'body', 'url', 'level', 'read', 'created_at']], 'links', 'meta']);
        expect($all->json('data.0.title'))->toContain('අනුමැතිය අවශ්‍යයි');

        $first = $all->json('data.0.id');
        $this->postJson("/api/v1/notifications/{$first}/read")->assertNoContent();
        $this->getJson('/api/v1/notifications?unread=true')->assertJsonPath('meta.total', 2);
        $this->postJson('/api/v1/notifications/read-all')->assertNoContent();
        $this->getJson('/api/v1/notifications?unread=1')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.unread', 0);
    });

    it('validates paging parameters and needs a login', function () {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/notifications?per_page=500')->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    });
});
