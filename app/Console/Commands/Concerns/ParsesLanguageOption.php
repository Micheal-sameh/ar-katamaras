<?php

namespace App\Console\Commands\Concerns;

use App\Enums\Language;

/** The shared `--lang=*` option: language codes (ar, en, fr), or every configured language when omitted. */
trait ParsesLanguageOption
{
    /** @return list<Language>|null null (after printing an error) when a code is unknown */
    protected function languages(): ?array
    {
        $codes = array_filter((array) $this->option('lang'), fn ($c) => $c !== '*' && $c !== '');
        if ($codes === []) {
            return config('readings.languages');
        }

        $languages = [];
        foreach ($codes as $code) {
            $language = Language::fromCode($code);
            if ($language === null) {
                $this->error("Unknown language '{$code}'. Use: ".implode(', ', Language::codes()));

                return null;
            }
            $languages[] = $language;
        }

        return $languages;
    }
}
