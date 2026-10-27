<?php

namespace App\Services;

/**
 * Decides whether a typed church code is accepted.
 *
 * Two ways to say what is valid (use either, or both):
 *  - READINGS_ACCESS_CODES: the codes that were handed out, separated by commas. Each can be removed on its own.
 *  - READINGS_ACCESS_PATTERN: a format every valid code matches (a regular expression, e.g. e1c1f[0-9]*nr[0-9]+).
 *    Weaker, because anyone who knows the format can try codes; the wrong-guess limit slows that down.
 * With neither set, the readings are open to everyone. Comparison ignores case and surrounding spaces.
 */
class ChurchAccess
{
    public function enabled(): bool
    {
        return $this->codes() !== [] || $this->pattern() !== '';
    }

    public function accepts(string $given): bool
    {
        $given = strtolower(trim($given));
        if ($given === '') {
            return false;
        }

        foreach ($this->codes() as $code) {
            if (hash_equals($code, $given)) {
                return true;
            }
        }

        $pattern = $this->pattern();

        return $pattern !== '' && @preg_match('~^(?:'.str_replace('~', '\~', $pattern).')$~i', $given) === 1;
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_values(array_filter(
            array_map(fn ($code) => strtolower(trim((string) $code)), (array) config('readings.access.codes')),
            fn (string $code) => $code !== '',
        ));
    }

    private function pattern(): string
    {
        return trim((string) config('readings.access.pattern'));
    }
}
