<?php

namespace App\Modules\Licensing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\LicenceGate;
use App\Modules\Core\Contracts\SettingsService;
use App\Modules\Licensing\DTOs\LicenceStatus;
use App\Modules\Licensing\Exceptions\LicenceException;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Licensing\Support\LicenceToken;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The installation's licence: which one is in force, where today falls against it, and what that means.
 *
 *  - The licence with the newest issue date (that has started) is the one in force. A renewal is simply a newer licence;
 *    the vendor can also shorten or end a licence by issuing a newer one.
 *  - After its last day: `grace_days` of normal work with a warning, `readonly_days` of look-and-print only, then locked.
 *  - A new installation has a trial of `trial_days` from its first use, with the same phases after it.
 *  - Nothing is restricted unless enforcement is on (production by default), so development and tests run freely.
 */
final class LicenceService implements LicenceGate
{
    /** @var array<string, LicenceStatus> */
    private array $memo = [];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function enforcing(): bool
    {
        $setting = config('licensing.enforce');

        return $setting === null ? app()->isProduction() : filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    /** The identity the vendor binds a licence to; created on first use. */
    public function installationId(): string
    {
        $id = $this->settings->get('licensing.installation_id');
        if (! is_string($id) || $id === '') {
            $id = 'inst_'.Str::lower(Str::random(20));
            $this->settings->set('licensing.installation_id', $id);
        }

        return $id;
    }

    public function status(?CarbonImmutable $now = null): LicenceStatus
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone((string) config('core.display_timezone'))->startOfDay();
        $key = $today->toDateString();
        if ($now === null && isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $licence = $this->current($today);
        if ($licence !== null) {
            $source = 'licence';
            $end = $licence->valid_until->setTimezone((string) config('core.display_timezone'))->startOfDay();
        } else {
            $source = 'trial';
            $end = $this->firstRun($today)->addDays((int) config('licensing.trial_days'))->startOfDay();
        }

        $over = $today->greaterThan($end) ? (int) $end->diffInDays($today) : 0;
        $grace = (int) config('licensing.grace_days');
        $readonly = (int) config('licensing.readonly_days');
        $state = match (true) {
            $over === 0 => 'active',
            $over <= $grace => 'grace',
            $over <= $grace + $readonly => 'readonly',
            default => 'locked',
        };

        $status = new LicenceStatus(
            state: $state, source: $source, validUntil: $end->toDateString(),
            daysLeft: $over === 0 ? (int) $today->diffInDays($end) : 0, daysOver: $over,
            plan: $licence?->plan, company: $licence?->company, serial: $licence?->serial, maxUsers: $licence?->max_users, modules: $licence?->modules,
            readonlyFrom: $end->addDays($grace + 1)->toDateString(), lockedFrom: $end->addDays($grace + $readonly + 1)->toDateString(),
        );
        if ($now === null) {
            $this->memo[$key] = $status;
        }

        return $status;
    }

    /** The licence in force today: the newest issue that has started (else the newest issue). */
    public function current(?CarbonImmutable $today = null): ?Licence
    {
        $today ??= CarbonImmutable::now((string) config('core.display_timezone'))->startOfDay();
        $licences = Licence::query()->where('installation_id', $this->installationId())->orderByDesc('issued_at')->orderByDesc('id')->get()
            ->filter(fn (Licence $l) => $this->trusted($l))->values();

        return $licences->first(fn (Licence $l) => $l->valid_from->toDateString() <= $today->toDateString()) ?? $licences->first();
    }

    /**
     * Install a licence the vendor sent. Installing the same licence again changes nothing.
     *
     * @throws LicenceException
     */
    public function install(string $token, ?int $by = null): Licence
    {
        $payload = LicenceToken::verify($token, config('licensing.public_key'));

        if ($payload['installation'] !== $this->installationId()) {
            throw LicenceException::make('wrong_installation', 'This licence was issued for another installation. Check the installation ID with the vendor.');
        }
        $existing = Licence::query()->where('serial', $payload['serial'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $issued = CarbonImmutable::parse($payload['issued_at']);
        if ($issued->greaterThan(CarbonImmutable::now()->addDays(2))) {
            throw LicenceException::make('from_the_future', 'This licence is dated in the future. Check the date and time of this server.');
        }
        $latest = Licence::query()->where('installation_id', $this->installationId())->orderByDesc('issued_at')->first();
        if ($latest !== null && $issued->lessThan($latest->issued_at)) {
            throw LicenceException::make('not_newer', 'A newer licence is already installed; this one is older.');
        }

        $licence = Licence::query()->create([
            'serial' => $payload['serial'], 'token' => trim($token), 'installation_id' => $payload['installation'], 'company' => $payload['company'], 'plan' => $payload['plan'],
            'valid_from' => $payload['valid_from'], 'valid_until' => $payload['valid_until'], 'issued_at' => $issued, 'max_users' => $payload['max_users'],
            'modules' => $payload['modules'], 'entered_by' => $by,
        ]);
        $this->memo = [];
        $this->audit->record('licensing.licence_installed', null, [], ['serial' => $licence->serial, 'plan' => $licence->plan, 'valid_until' => $licence->valid_until->toDateString()]);

        return $licence;
    }

    public function banner(): ?array
    {
        if (! $this->enforcing()) {
            return null;
        }
        $s = $this->status();
        $url = route('licence.show');
        $warn = (int) config('licensing.warn_days');

        return match (true) {
            $s->state === 'grace' => ['level' => 'warning', 'message' => __('licensing::ui.banner.grace', ['date' => $s->validUntil, 'days' => max(0, (int) config('licensing.grace_days') - $s->daysOver + 1), 'readonly' => $s->readonlyFrom]), 'url' => $url],
            $s->state === 'readonly' => ['level' => 'danger', 'message' => __('licensing::ui.banner.readonly', ['locked' => $s->lockedFrom]), 'url' => $url],
            $s->state === 'locked' => ['level' => 'danger', 'message' => __('licensing::ui.banner.locked'), 'url' => $url],
            $s->source === 'trial' => ['level' => $s->daysLeft <= $warn ? 'warning' : 'info', 'message' => __('licensing::ui.banner.trial', ['days' => $s->daysLeft, 'date' => $s->validUntil]), 'url' => $url],
            $s->daysLeft <= $warn => ['level' => 'warning', 'message' => __('licensing::ui.banner.ending', ['days' => $s->daysLeft, 'date' => $s->validUntil]), 'url' => $url],
            default => null,
        };
    }

    public function allowsModule(string $module): bool
    {
        if (! $this->enforcing() || $module === 'core' || $module === 'licensing') {
            return true;
        }
        $modules = $this->status()->modules;

        return $modules === null || in_array($module, $modules, true);
    }

    /** A stored licence is believed only while its signature still checks out (a changed database row is ignored). */
    private function trusted(Licence $licence): bool
    {
        $key = config('licensing.public_key');
        if (! is_string($key) || $key === '') {
            return true;   // nothing to check with; only possible where licences cannot be installed at all
        }
        try {
            $p = LicenceToken::verify($licence->token, $key);
        } catch (BusinessException) {
            return false;
        }

        return $p['serial'] === $licence->serial && $p['installation'] === $licence->installation_id && $p['valid_until'] === $licence->valid_until->toDateString()
            && $p['valid_from'] === $licence->valid_from->toDateString() && $p['plan'] === $licence->plan;
    }

    private function firstRun(CarbonImmutable $today): CarbonImmutable
    {
        $value = $this->settings->get('licensing.first_run_at');
        if (! is_string($value) || $value === '') {
            $value = $today->toDateString();
            $this->settings->set('licensing.first_run_at', $value);
        }

        return CarbonImmutable::parse($value, (string) config('core.display_timezone'))->startOfDay();
    }
}
