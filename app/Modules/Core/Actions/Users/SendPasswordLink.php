<?php

namespace App\Modules\Core\Actions\Users;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Mails a person a one-time link to choose a password (a new account, a forgotten password). The answer never says whether
 * the address exists, so the form cannot be used to find out who has an account. Switched-off accounts get no link.
 */
final class SendPasswordLink
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return bool false only when the mail system refused the message or could not be reached (callers decide what to tell whom) */
    public function __invoke(User|string $who, ?User $by = null): bool
    {
        $user = $who instanceof User ? $who : User::query()->where('email', mb_strtolower(trim($who)))->first();
        if ($user === null || ! $user->is_active || $user->user_type !== User::TYPE_INTERNAL) {
            return true;
        }

        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (TransportExceptionInterface $e) {
            report($e);
            $this->audit->record('user.password_link_sent', $user, [], [], ['by' => $by?->id, 'result' => 'mail_failed']);

            return false;
        }
        $this->audit->record('user.password_link_sent', $user, [], [], ['by' => $by?->id, 'result' => $status]);

        return true;
    }
}
