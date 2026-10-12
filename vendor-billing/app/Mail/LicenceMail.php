<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\IssuedLicence;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LicenceMail extends Mailable
{
    public function __construct(public IssuedLicence $licence, public Customer $customer) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your licence is renewed until '.$this->licence->valid_until->format('j M Y'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.licence');
    }
}
