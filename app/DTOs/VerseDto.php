<?php

namespace App\DTOs;

/** number 0 means "unnumbered" (e.g. text imported from slides). */
final readonly class VerseDto
{
    public function __construct(
        public int $number,
        public string $text,
    ) {}

    public function toArray(): array
    {
        return ['number' => $this->number, 'text' => $this->text];
    }

    public static function fromArray(array $data): self
    {
        return new self((int) ($data['number'] ?? 0), (string) ($data['text'] ?? ''));
    }
}
