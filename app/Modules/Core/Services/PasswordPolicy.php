<?php

namespace App\Modules\Core\Services;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * The one password rule for the whole product (set by an administrator's link, forgot-password, change password):
 * at least 12 characters with upper and lower case and a digit, not made of the person's own e-mail name, and — when
 * switched on — not found in known data breaches (checked with the k-anonymity service, which fails open if unreachable).
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** @return list<mixed> validation rules for a new password */
    public function rules(?string $email = null): array
    {
        $rule = Password::min(self::MIN_LENGTH)->mixedCase()->numbers();
        if (config('core.auth.check_breached')) {
            $rule = $rule->uncompromised();
        }

        return ['required', 'string', 'max:200', $rule, $this->notYourName($email)];
    }

    private function notYourName(?string $email): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($email): void {
            $local = mb_strtolower((string) strstr((string) $email, '@', true));
            if (mb_strlen($local) >= 4 && str_contains(mb_strtolower((string) $value), $local)) {
                $fail(__('core::ui.users.password_has_name'));
            }
        };
    }
}
