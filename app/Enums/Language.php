<?php

namespace App\Enums;

/** Backing value is the Katameros languageId. */
enum Language: int
{
    case French = 1;
    case English = 2;
    case Arabic = 3;

    public function code(): string
    {
        return match ($this) {
            self::French => 'fr',
            self::English => 'en',
            self::Arabic => 'ar',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::French => 'Français',
            self::English => 'English',
            self::Arabic => 'العربية',
        };
    }

    public static function fromCode(string $code): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->code() === strtolower($code)) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $language) => $language->code(), self::cases());
    }
}
