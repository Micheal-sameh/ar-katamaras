<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent when a pre-cache run (weekly, daily catch-up or monthly) could not store every day, or crashed. */
class ReadingsWarmFailed extends Mailable
{
    /**
     * @param  list<string>  $missing  "ar:2026-11-10" ...
     * @param  list<string>  $errors  first error messages, for diagnosis
     */
    public function __construct(
        public string $reason,
        public string $range,
        public array $missing = [],
        public array $errors = [],
        public string $job = 'Monthly pre-cache',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Readings] '.$this->job.' failed: '.$this->reason);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.readings-warm-failed');
    }
}
