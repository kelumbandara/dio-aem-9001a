<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Employee;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;

it('records created, updated and deleted model events with old and new values', function () {
    $branch = Branch::factory()->create(['name' => 'Before']);
    $branch->update(['name' => 'After']);
    $branch->delete();

    $events = AuditLog::where('auditable_id', $branch->id)->where('auditable_type', $branch->getMorphClass())->orderBy('id')->get();

    expect($events->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($events[1]->old_values)->toBe(['name' => 'Before'])
        ->and($events[1]->new_values)->toBe(['name' => 'After'])
        ->and($events[0]->channel)->toBe('console');
});

it('does not log an update that changed nothing', function () {
    $branch = Branch::factory()->create();
    $before = AuditLog::count();

    $branch->update(['name' => $branch->name]);
    $branch->touch();

    expect(AuditLog::count())->toBe($before);
});

it('redacts passwords, tokens and NIC values', function () {
    $user = User::factory()->create();
    $user->update(['password' => 'a-brand-new-password']);

    $entry = AuditLog::where('event', 'updated')->where('auditable_id', $user->id)->latest('id')->first();
    expect($entry->new_values['password'])->toBe('***')->and($entry->old_values['password'])->toBe('***');

    app(AuditLogger::class)->record('custom', null, [], ['nic' => '199012345678', 'nested' => ['api_token' => 'abc', 'city' => 'Kandy']]);
    $custom = AuditLog::where('event', 'custom')->first();
    expect($custom->new_values['nic'])->toBe('***')
        ->and($custom->new_values['nested']['api_token'])->toBe('***')
        ->and($custom->new_values['nested']['city'])->toBe('Kandy');
});

it('does not redact words that merely contain a sensitive fragment', function () {
    app(AuditLogger::class)->record('custom', null, [], ['technician' => 'Saman', 'mechanic_name' => 'Kamal']);

    expect(AuditLog::where('event', 'custom')->first()->new_values)->toBe(['technician' => 'Saman', 'mechanic_name' => 'Kamal']);
});

it('stores employee NIC encrypted at rest and keeps it out of the audit log', function () {
    $employee = Employee::factory()->create(['nic' => '199012345678']);

    expect(DB::table('employees')->where('id', $employee->id)->value('nic'))->not->toContain('199012345678')
        ->and($employee->fresh()->nic)->toBe('199012345678');

    $entry = AuditLog::where('auditable_type', $employee->getMorphClass())->where('event', 'created')->first();
    expect($entry->new_values['nic'])->toBe('***');
});

it('captures actor, request id and channel for web requests', function () {
    $user = userWithPermissions('core.audit.view');
    $this->actingAs($user)->withHeader('X-Request-Id', 'req-12345678')->post('/locale', ['locale' => 'ta']);

    $entry = AuditLog::where('event', 'updated')->where('auditable_id', $user->id)->latest('id')->first();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->correlation_id)->toBe('req-12345678')
        ->and($entry->channel)->toBe('web')
        ->and($entry->ip_address)->not->toBeNull();
});

it('refuses to update or delete audit rows, in the model and in the database', function () {
    app(AuditLogger::class)->record('probe');
    $entry = AuditLog::first();

    expect(fn () => $entry->update(['event' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);

    // bypass the model entirely: the PostgreSQL trigger must still block it
    expect(fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['event' => 'tampered']))->toThrow(QueryException::class);
    expect(fn () => DB::table('audit_logs')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'append-only trigger is PostgreSQL-specific');

it('lets only permitted users see the audit log page', function () {
    $this->actingAs(userWithPermissions('core.branch.view'))->get('/admin/audit-log')->assertForbidden();
    $this->actingAs(userWithPermissions('core.audit.view'))->get('/admin/audit-log')->assertOk()->assertSee(__('core::ui.audit.title'));
});
