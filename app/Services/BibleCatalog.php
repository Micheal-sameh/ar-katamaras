<?php

namespace App\Services;

use App\Enums\Language;

/**
 * The Bible versions a visitor may pick (an allow-list: the id goes to the Katameros API, so it must
 * never come straight from a request). Id 0 inside the app always means "the language's default".
 */
class BibleCatalog
{
    public function defaultId(Language $language): int
    {
        return (int) config("readings.bibles.{$language->value}.default", 0);
    }

    /** @return array<int, string> id => name */
    public function available(Language $language): array
    {
        return (array) config("readings.bibles.{$language->value}.available", []);
    }

    public function isAllowed(Language $language, int $bibleId): bool
    {
        return $bibleId === 0 || array_key_exists($bibleId, $this->available($language));
    }

    /** Allowed id -> stored id: the default Bible is stored as 0, so each day has one canonical copy. */
    public function normalize(Language $language, int $bibleId): int
    {
        if (! $this->isAllowed($language, $bibleId) || $bibleId === $this->defaultId($language)) {
            return 0;
        }

        return $bibleId;
    }

    /** @return list<array{id: int, name: string, default: bool}> */
    public function list(Language $language): array
    {
        $default = $this->defaultId($language);

        return array_values(array_map(
            fn (int $id, string $name) => ['id' => $id, 'name' => $name, 'default' => $id === $default],
            array_keys($this->available($language)),
            $this->available($language),
        ));
    }
}
