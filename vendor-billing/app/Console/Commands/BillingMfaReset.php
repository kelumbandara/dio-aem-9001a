<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Activity;
use App\Services\MfaService;
use Illuminate\Console\Command;

class BillingMfaReset extends Command
{
    protected $signature = 'billing:mfa-reset {email : the user who lost their authenticator}';

    protected $description = 'Remove a user\'s authenticator link so they can set it up again at next sign-in';

    public function handle(MfaService $mfa, Activity $activity): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->components->error('No such user.');

            return self::FAILURE;
        }
        $mfa->reset($user);
        $activity->log('user.mfa_reset', $user, ['by' => 'console']);
        $this->components->info('Done. They set up the authenticator again at next sign-in.');

        return self::SUCCESS;
    }
}
