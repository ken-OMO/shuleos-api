<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SchoolUserInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $schoolName,
        public readonly string $schoolCode,
        public readonly string $email,
        public readonly string $temporaryPassword,
        public readonly string $loginUrl,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your ShuleOS school user invitation');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.school-user-invitation');
    }
}
