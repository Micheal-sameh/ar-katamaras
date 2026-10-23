<?php

namespace App\Enums;

use App\Support\TextNormalizer;

enum ReadingType: string
{
    // Declaration order matters for fromTitle(): the combined title is tested before its parts.
    case PsalmGospel = 'psalm_gospel';
    case Pauline = 'pauline';
    case Catholic = 'catholic';
    case Praxis = 'praxis';
    case Psalm = 'psalm';
    case Gospel = 'gospel';
    case Synaxarium = 'synaxarium';
    case Other = 'other';

    public function label(Language $language): string
    {
        return match ($language) {
            Language::Arabic => match ($this) {
                self::Pauline => 'البولس',
                self::Catholic => 'الكاثوليكون',
                self::Praxis => 'الإبركسيس',
                self::Psalm => 'المزمور',
                self::Gospel => 'الإنجيل',
                self::PsalmGospel => 'المزمور والإنجيل',
                self::Synaxarium => 'السنكسار',
                self::Other => 'قراءة',
            },
            Language::French => match ($this) {
                self::Pauline => 'Épître de Paul',
                self::Catholic => 'Épître catholique',
                self::Praxis => 'Actes',
                self::Psalm => 'Psaume',
                self::Gospel => 'Évangile',
                self::PsalmGospel => 'Psaume et Évangile',
                self::Synaxarium => 'Synaxaire',
                self::Other => 'Lecture',
            },
            default => match ($this) {
                self::Pauline => 'Pauline Epistle',
                self::Catholic => 'Catholic Epistle',
                self::Praxis => 'Praxis',
                self::Psalm => 'Psalm',
                self::Gospel => 'Gospel',
                self::PsalmGospel => 'Psalm & Gospel',
                self::Synaxarium => 'Synaxarium',
                self::Other => 'Reading',
            },
        };
    }

    private function keywords(): array
    {
        return match ($this) {
            self::PsalmGospel => ['المزمور والانجيل', 'psalm & gospel', 'psalm and gospel', 'psaume et evangile'],
            self::Pauline => ['بولس', 'pauline', 'epitre de paul'],
            self::Catholic => ['كاثوليكون', 'catholic', 'catholique'],
            self::Praxis => ['ابركسيس', 'praxis', 'actes', 'acts'],
            self::Psalm => ['مزمور', 'psalm', 'psaume'],
            self::Gospel => ['انجيل', 'gospel', 'evangile'],
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
