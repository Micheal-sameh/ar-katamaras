<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent when the monthly pre-cache could not store every day, or crashed. */
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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Readings] Monthly pre-cache failed: '.$this->reason);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.readings-warm-failed');
    }
}
