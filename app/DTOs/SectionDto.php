<?php

namespace App\DTOs;

use App\Enums\SectionType;

final readonly class SectionDto
{
    /** @param list<SubSectionDto> $subSections */
    public function __construct(
        public SectionType $type,
        public string $title,
        public array $subSections,
    ) {}

    public function hasContent(): bool
    {
        foreach ($this->subSections as $subSection) {
            if ($subSection->hasContent()) {
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
            'subSections' => array_map(fn (SubSectionDto $s) => $s->toArray(), $this->subSections),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            SectionType::tryFrom($data['type'] ?? '') ?? SectionType::Other,
            (string) ($data['title'] ?? ''),
            array_map(SubSectionDto::fromArray(...), $data['subSections'] ?? []),
        );
    }
}
