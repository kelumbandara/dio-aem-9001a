<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/** Authenticator-app (TOTP) sign-in for every staff member. A lost phone is reset with `php artisan billing:mfa-reset`. */
final class MfaService
{
    public function __construct(private readonly Google2FA $totp) {}

    /** @return array{secret: string, qr_svg: string} the pending enrolment (kept until confirmed so a refresh shows the same code) */
    public function pending(User $user): array
    {
        if ($user->mfa_confirmed_at !== null) {
            throw new \LogicException('MFA is already set up.');
        }
        if (blank($user->mfa_secret)) {
            $user->forceFill(['mfa_secret' => $this->totp->generateSecretKey(32)])->save();
        }
        $uri = $this->totp->getQRCodeUrl('DIO Billing', $user->email, $user->mfa_secret);

        return ['secret' => $user->mfa_secret, 'qr_svg' => (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($uri)];
    }

    public function confirm(User $user, string $code): bool
    {
        if ($user->mfa_confirmed_at !== null || blank($user->mfa_secret)) {
            return false;
        }
        $step = $this->totp->verifyKeyNewer($user->mfa_secret, $this->normalise($code), 0, 1);
        if ($step === false) {
            return false;
        }
        $user->forceFill(['mfa_confirmed_at' => now(), 'mfa_last_timestep' => $step])->save();

        return true;
    }

    /** A code is accepted once only: a used time step cannot be replayed. */
    public function verify(User $user, string $code): bool
    {
        if ($user->mfa_confirmed_at === null) {
            return false;
        }
        $step = $this->totp->verifyKeyNewer($user->mfa_secret, $this->normalise($code), $user->mfa_last_timestep ?? 0, 1);
        if ($step === false) {
            return false;
        }
        $user->forceFill(['mfa_last_timestep' => $step])->save();

        return true;
    }

    public function reset(User $user): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_last_timestep' => null])->save();
    }

    private function normalise(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }
}
