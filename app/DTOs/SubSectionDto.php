<?php

namespace App\DTOs;

use App\Enums\ReadingType;

final readonly class SubSectionDto
{
    /** @param list<ReadingDto> $readings */
    public function __construct(
        public ReadingType $type,
        public string $title,
        public ?string $introduction,
        public array $readings,
    ) {}

    public function hasContent(): bool
    {
        foreach ($this->readings as $reading) {
            if ($reading->hasContent()) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'introduction' => $this->introduction,
            'readings' => array_map(fn (ReadingDto $r) => $r->toArray(), $this->readings),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            ReadingType::tryFrom($data['type'] ?? '') ?? ReadingType::Other,
            (string) ($data['title'] ?? ''),
            $data['introduction'] ?? null,
            array_map(ReadingDto::fromArray(...), $data['readings'] ?? []),
        );
    }
}
