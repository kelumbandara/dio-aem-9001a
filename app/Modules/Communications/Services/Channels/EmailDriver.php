<?php

namespace App\Modules\Communications\Services\Channels;

use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/** Plain-text e-mail through the application's normal mail settings (MAIL_*), with a one-click "stop these e-mails" link at the foot. */
final class EmailDriver implements ChannelDriver
{
    public function configured(): bool
    {
        return (string) config('mail.default') !== '' && (string) config('mail.from.address') !== '';
    }

    public function send(Message $message, ?Template $template): string
    {
        $stop = URL::signedRoute('comm.unsubscribe', ['message' => $message->public_id]);
        $text = $message->body."\n\n--\n".__('communications::ui.email_footer')."\n".$stop;

        try {
            Mail::raw($text, function ($mail) use ($message) {
                $mail->to($message->address)->subject((string) ($message->subject ?? ''));
            });
        } catch (TransportExceptionInterface $e) {
            throw new ChannelException('comm.email_transport', 'The mail server did not accept the message.', true);
        } catch (Throwable $e) {
            report($e);

            throw new ChannelException('comm.email_failed', 'The e-mail could not be sent.', false);
        }

        return $message->public_id;
    }
}
