<?php

namespace App\DTOs;

final readonly class ReadingDto
{
    /**
     * @param  list<PassageDto>  $passages
     * @param  list<string>  $paragraphs  plain-text paragraphs (Synaxarium text, slide text); never HTML
     */
    public function __construct(
        public string $title,
        public ?string $introduction,
        public ?string $conclusion,
        public array $passages,
        public array $paragraphs = [],
    ) {}

    public function hasContent(): bool
    {
        if ($this->paragraphs !== []) {
            return true;
        }
        foreach ($this->passages as $passage) {
            if ($passage->verses !== []) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'introduction' => $this->introduction,
            'conclusion' => $this->conclusion,
            'passages' => array_map(fn (PassageDto $p) => $p->toArray(), $this->passages),
            'paragraphs' => $this->paragraphs,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['title'] ?? ''),
            $data['introduction'] ?? null,
            $data['conclusion'] ?? null,
            array_map(PassageDto::fromArray(...), $data['passages'] ?? []),
            array_values(array_map('strval', $data['paragraphs'] ?? [])),
        );
    }
}
