<?php

namespace App\Modules\Licensing\Console;

use App\Modules\Licensing\Exceptions\LicenceException;
use App\Modules\Licensing\Services\LicenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks the vendor's billing system for this installation's newest licence and installs it, so a paid renewal arrives
 * without anyone pasting text. Does nothing unless VENDOR_BILLING_URL and VENDOR_BILLING_TOKEN are set. The licence is
 * still checked offline against the vendor's public key, exactly like a pasted one, so the billing system is trusted for
 * delivery only, not for the licence's content.
 */
class LicenceFetch extends Command
{
    protected $signature = 'licence:fetch';

    protected $description = 'Fetch the newest licence for this installation from the vendor billing system';

    public function handle(LicenceService $licences): int
    {
        $url = rtrim((string) config('licensing.vendor_url'), '/');
        $token = (string) config('licensing.vendor_token');
        if ($url === '' || $token === '') {
            $this->components->info('Automatic renewal is not set up here (VENDOR_BILLING_URL / VENDOR_BILLING_TOKEN).');

            return self::SUCCESS;
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(15)->get($url.'/api/v1/installations/'.rawurlencode($licences->installationId()).'/licence');
        } catch (Throwable $e) {
            $this->components->error('The vendor billing system could not be reached: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->status() === 404) {
            $this->components->info('No licence has been issued for this installation yet.');

            return self::SUCCESS;
        }
        if (! $response->successful() || ! is_string($response->json('data.licence'))) {
            $this->components->error('The vendor billing system refused the request (HTTP '.$response->status().'). Check VENDOR_BILLING_TOKEN.');

            return self::FAILURE;
        }

        try {
            $before = $licences->current();
            $installed = $licences->install($response->json('data.licence'));
        } catch (LicenceException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($before?->serial === $installed->serial
            ? "Already up to date (licence {$installed->serial}, until {$installed->valid_until->toDateString()})."
            : "Installed licence {$installed->serial}, valid until {$installed->valid_until->toDateString()}.");

        return self::SUCCESS;
    }
}
