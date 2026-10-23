<?php

namespace App\DTOs;

use App\Enums\CopticMonth;
use App\Enums\Language;

final readonly class CopticDateDto
{
    public function __construct(
        public int $day,
        public CopticMonth $month,
        public int $year,
    ) {}

    public function format(Language $language): string
    {
        return sprintf('%d %s %d', $this->day, $this->month->label($language), $this->year);
    }

    public function toArray(): array
    {
        return ['day' => $this->day, 'month' => $this->month->value, 'year' => $this->year];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['day'],
            CopticMonth::from((int) $data['month']),
            (int) $data['year'],
        );
    }
}
