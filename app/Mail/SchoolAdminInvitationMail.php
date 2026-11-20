<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SchoolAdminInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $schoolName,
        public readonly string $username,
        public readonly string $temporaryPassword,
        public readonly string $loginUrl,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your ShuleOS school administrator invitation');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.school-admin-invitation');
    }
}
