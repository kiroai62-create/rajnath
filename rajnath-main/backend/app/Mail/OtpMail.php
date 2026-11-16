<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OtpMail extends Mailable
{
    public function __construct(public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        // The code stays out of the subject: inbox previews, lock screens and mail logs would show it.
        return new Envelope(subject: 'Your sign-in code for '.config('brand')['brand.name']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.otp', text: 'mail.otp-text');
    }
}
