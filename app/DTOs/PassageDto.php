<?php

namespace App\DTOs;

final readonly class PassageDto
{
    /** @param list<VerseDto> $verses */
    public function __construct(
        public ?string $bookTranslation,
        public string $ref,
        public array $verses,
    ) {}

    public function toArray(): array
    {
        return [
            'bookTranslation' => $this->bookTranslation,
            'ref' => $this->ref,
            'verses' => array_map(fn (VerseDto $v) => $v->toArray(), $this->verses),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['bookTranslation'] ?? null,
            (string) ($data['ref'] ?? ''),
            array_map(VerseDto::fromArray(...), $data['verses'] ?? []),
        );
    }
}
