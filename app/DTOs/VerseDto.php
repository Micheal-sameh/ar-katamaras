<?php

namespace App\DTOs;

/** number 0 means "unnumbered"; chapter 0 means "unknown". */
final readonly class VerseDto
{
    public function __construct(
        public int $number,
        public string $text,
        public int $chapter = 0,
    ) {}

    public function toArray(): array
    {
        return ['number' => $this->number, 'text' => $this->text, 'chapter' => $this->chapter];
    }

    public static function fromArray(array $data): self
    {
        return new self((int) ($data['number'] ?? 0), (string) ($data['text'] ?? ''), (int) ($data['chapter'] ?? 0));
    }
}
