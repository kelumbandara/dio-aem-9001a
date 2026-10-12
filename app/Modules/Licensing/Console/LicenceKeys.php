<?php

namespace App\Modules\Licensing\Console;

use App\Modules\Licensing\Support\LicenceToken;
use Illuminate\Console\Command;

/** For the vendor, once: makes the signing key pair. The secret key is only shown here, never stored by the product. */
class LicenceKeys extends Command
{
    protected $signature = 'licence:keys';

    protected $description = 'Generate the vendor\'s licence signing keys (vendor only; prints the secret key once)';

    public function handle(): int
    {
        [$public, $secret] = LicenceToken::generateKeys();

        $this->components->warn('Keep the SECRET key only on the vendor\'s own machine (a password manager or an encrypted drive). It is not stored anywhere and cannot be recovered.');
        $this->line('');
        $this->line('LICENCE_PUBLIC_KEY (put in every installation\'s environment):');
        $this->line($public);
        $this->line('');
        $this->line('LICENCE_PRIVATE_KEY (vendor only; used by licence:issue):');
        $this->line($secret);

        return self::SUCCESS;
    }
}
