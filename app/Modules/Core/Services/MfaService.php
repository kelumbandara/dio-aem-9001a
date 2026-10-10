<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\MfaSecret;
use App\Modules\Core\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment and verification (RFC 6238) with single-use recovery codes.
 */
final class MfaService
{
    public function __construct(
        private readonly Google2FA $totp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start (or restart) enrolment. The secret is not active until confirm() succeeds.
     *
     * @return array{secret: string, uri: string, qr_svg: string}
     */
    public function begin(User $user): array
    {
        $secret = $this->totp->generateSecretKey(32);

        MfaSecret::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['secret' => $secret, 'recovery_codes' => null, 'confirmed_at' => null, 'last_used_timestep' => null],
        );

        $uri = $this->totp->getQRCodeUrl(config('core.mfa.issuer'), $user->email, $secret);

        return ['secret' => $secret, 'uri' => $uri, 'qr_svg' => $this->qrSvg($uri)];
    }

    /**
     * The unconfirmed enrolment if one exists (so a page refresh keeps the same QR code), else a new one.
     *
     * @return array{secret: string, uri: string, qr_svg: string}
     */
    public function pendingOrBegin(User $user): array
    {
        $pending = $user->mfaSecret()->whereNull('confirmed_at')->first();
        if ($pending === null) {
            return $this->begin($user);
        }

        $uri = $this->totp->getQRCodeUrl(config('core.mfa.issuer'), $user->email, $pending->secret);

        return ['secret' => $pending->secret, 'uri' => $uri, 'qr_svg' => $this->qrSvg($uri)];
    }

    /**
     * Confirm enrolment with a code from the authenticator app.
     *
     * @return list<string>|null plain recovery codes (shown once), or null when the code is wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        $record = $user->mfaSecret()->first();
        if ($record === null || $record->confirmed_at !== null) {
            return null;
        }

        // Old step 0 (not null): with null the library returns `true` instead of the matched time step.
        $timestep = $this->totp->verifyKeyNewer($record->secret, $this->normalise($code), 0, 1);
        if ($timestep === false) {
            return null;
        }

        $plain = $this->generateRecoveryCodes();

        $record->forceFill([
            'confirmed_at' => now(),
            'last_used_timestep' => $timestep,
            'recovery_codes' => array_map(fn (string $c) => Hash::make($c), $plain),
        ])->save();

        $this->audit->record('mfa.enabled', $user);

        return $plain;
    }

    /** Verify a TOTP code or consume a recovery code. */
    public function verify(User $user, string $code): bool
    {
        $code = $this->normalise($code);

        return DB::transaction(function () use ($user, $code): bool {
            $record = MfaSecret::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->lockForUpdate()->first();
            if ($record === null) {
                return false;
            }

            // 6 digits = TOTP; anything else is tried as a recovery code.
            if (preg_match('/^\d{6}$/', $code) === 1) {
                $timestep = $this->totp->verifyKeyNewer($record->secret, $code, $record->last_used_timestep ?? 0, 1);
                if ($timestep === false) {
                    return false;
                }
                $record->forceFill(['last_used_timestep' => $timestep])->save();

                return true;
            }

            return $this->consumeRecoveryCode($user, $record, $code);
        });
    }

    public function disable(User $user): void
    {
        MfaSecret::query()->where('user_id', $user->id)->delete();
        $this->audit->record('mfa.disabled', $user);
    }

    private function consumeRecoveryCode(User $user, MfaSecret $record, string $code): bool
    {
        $hashes = $record->recovery_codes ?? [];

        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $record->forceFill(['recovery_codes' => array_values($hashes)])->save();
                $this->audit->record('mfa.recovery_code_used', $user, [], [], ['remaining' => count($hashes)]);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, (int) config('core.mfa.recovery_code_count', 8)))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    private function normalise(string $code): string
    {
        return strtolower(str_replace(' ', '', trim($code)));
    }

    private function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }
}
