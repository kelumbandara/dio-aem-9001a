<?php

namespace App\Modules\Licensing\Console;

use App\Modules\Licensing\Exceptions\LicenceException;
use App\Modules\Licensing\Support\LicenceToken;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * For the vendor: signs a licence for one installation. The secret key is read from a file (--key-file) or from the
 * LICENCE_PRIVATE_KEY environment variable, never from an argument, so it does not end up in a shell history.
 */
class LicenceIssue extends Command
{
    protected $signature = 'licence:issue
        {installation : the installation ID shown on the customer\'s licence screen}
        {--company= : company name printed on the licence}
        {--plan=standard : plan name}
        {--from= : first day (Y-m-d, default today)}
        {--until= : last day (Y-m-d)}
        {--months= : alternative to --until: this many months from --from}
        {--users= : user limit}
        {--modules= : comma-separated modules included (default all)}
        {--key-file= : file holding the secret key}';

    protected $description = 'Issue a signed licence for an installation (vendor only)';

    public function handle(): int
    {
        $key = $this->option('key-file') ? trim((string) @file_get_contents((string) $this->option('key-file'))) : (string) getenv('LICENCE_PRIVATE_KEY');
        if ($key === '') {
            $this->components->error('No secret key: use --key-file=<file> or set LICENCE_PRIVATE_KEY in the environment of this command.');

            return self::FAILURE;
        }

        $tz = (string) config('core.display_timezone');
        $from = $this->option('from') ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('from'), $tz) : CarbonImmutable::now($tz)->startOfDay();
        if ($from === null) {
            $this->components->error('--from must be a date like 2026-11-01.');

            return self::FAILURE;
        }
        if ($this->option('until')) {
            $until = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('until'), $tz);
        } elseif ($this->option('months')) {
            $until = $from->addMonthsNoOverflow((int) $this->option('months'))->subDay();
        } else {
            $this->components->error('Give --until=<date> or --months=<n>.');

            return self::FAILURE;
        }
        if ($until === null || $until->lessThan($from)) {
            $this->components->error('The last day must be a date on or after the first day.');

            return self::FAILURE;
        }

        $payload = [
            'v' => 1, 'serial' => 'L-'.CarbonImmutable::now()->format('Ymd').'-'.Str::upper(Str::random(6)), 'installation' => (string) $this->argument('installation'),
            'company' => (string) ($this->option('company') ?: 'Company'), 'plan' => (string) $this->option('plan'), 'valid_from' => $from->toDateString(), 'valid_until' => $until->toDateString(),
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
            'max_users' => $this->option('users') ? (int) $this->option('users') : null,
            'modules' => $this->option('modules') ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('modules'))))) : null,
        ];

        try {
            $token = LicenceToken::sign($payload, $key);
        } catch (LicenceException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Licence {$payload['serial']} for {$payload['company']}: {$payload['valid_from']} to {$payload['valid_until']}.");
        $this->line($token);

        return self::SUCCESS;
    }
}
