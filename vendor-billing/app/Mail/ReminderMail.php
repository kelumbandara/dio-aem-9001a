<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReminderMail extends Mailable
{
    /** @param  int  $days  days since the due date (negative = before it) */
    public function __construct(public Invoice $invoice, public int $days) {}

    public function envelope(): Envelope
    {
        $subject = $this->days < 0 ? 'Invoice '.$this->invoice->number.' is due soon' : 'Invoice '.$this->invoice->number.' is unpaid';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reminder');
    }
}
