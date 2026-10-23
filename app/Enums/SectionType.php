<?php

namespace App\Enums;

use App\Support\TextNormalizer;

enum SectionType: string
{
    case Vespers = 'vespers';
    case Matins = 'matins';
    case Liturgy = 'liturgy';
    case Synaxarium = 'synaxarium';
    case Other = 'other';

    public function label(Language $language): string
    {
        return match ($language) {
            Language::Arabic => match ($this) {
                self::Vespers => 'العشية',
                self::Matins => 'باكر',
                self::Liturgy => 'القداس',
                self::Synaxarium => 'السنكسار',
                self::Other => 'أخرى',
            },
            Language::French => match ($this) {
                self::Vespers => 'Vêpres',
                self::Matins => 'Matines',
                self::Liturgy => 'Liturgie',
                self::Synaxarium => 'Synaxaire',
                self::Other => 'Autre',
            },
            default => match ($this) {
                self::Vespers => 'Vespers',
                self::Matins => 'Matins',
                self::Liturgy => 'Liturgy',
                self::Synaxarium => 'Synaxarium',
                self::Other => 'Other',
            },
        };
    }

    /** Normalized words that identify the section in a title of any supported language. */
    private function keywords(): array
    {
        return match ($this) {
            self::Vespers => ['عشيه', 'vespers', 'vepres'],
            self::Matins => ['باكر', 'matins', 'matines'],
            self::Liturgy => ['قداس', 'liturgy', 'liturgie'],
            self::Synaxarium => ['سنكسار', 'synaxar', 'synaxair'],
            self::Other => [],
        };
    }

    public static function fromTitle(string $title): self
    {
        $normalized = TextNormalizer::forMatching($title);
        foreach (self::cases() as $case) {
            foreach ($case->keywords() as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $case;
                }
            }
        }

        return self::Other;
    }
}
