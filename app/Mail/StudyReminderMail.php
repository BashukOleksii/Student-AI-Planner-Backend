<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class StudyReminderMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] private string $reminderMessage, private bool $inboxAvailable = true) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Навчальне нагадування');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.study-reminder', text: 'mail.study-reminder-text',
            with: ['reminderMessage' => $this->reminderMessage, 'inboxAvailable' => $this->inboxAvailable]);
    }
}
