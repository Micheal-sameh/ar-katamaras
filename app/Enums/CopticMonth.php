<?php

namespace App\Enums;

enum CopticMonth: int
{
    case Thout = 1;
    case Paopi = 2;
    case Hathor = 3;
    case Koiak = 4;
    case Tobi = 5;
    case Meshir = 6;
    case Paremhat = 7;
    case Paremoude = 8;
    case Pashons = 9;
    case Paoni = 10;
    case Epip = 11;
    case Mesori = 12;
    case Nasie = 13;

    public function arabic(): string
    {
        return match ($this) {
            self::Thout => 'توت',
            self::Paopi => 'بابه',
            self::Hathor => 'هاتور',
            self::Koiak => 'كيهك',
            self::Tobi => 'طوبه',
            self::Meshir => 'أمشير',
            self::Paremhat => 'برمهات',
            self::Paremoude => 'برمودة',
            self::Pashons => 'بشنس',
            self::Paoni => 'بؤونة',
            self::Epip => 'أبيب',
            self::Mesori => 'مسرى',
            self::Nasie => 'النسيء',
        };
    }

    public function english(): string
    {
        return $this->name;
    }

    public function label(Language $language): string
    {
        return $language === Language::Arabic ? $this->arabic() : $this->english();
    }

    public function days(bool $leapYear = false): int
    {
        return $this === self::Nasie ? ($leapYear ? 6 : 5) : 30;
    }
}
