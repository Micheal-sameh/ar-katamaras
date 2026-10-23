<?php

namespace App\Support;

final class TextNormalizer
{
    /** Lower-case, drop Arabic diacritics and unify letter variants so titles from any source compare equal. */
    public static function forMatching(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ô' => 'o',
        ]);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /**
     * Plain-text paragraphs from untrusted HTML. Nothing is ever passed on as markup.
     *
     * @return list<string>
     */
    public static function htmlToParagraphs(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|h[1-6])>#i', "\n\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $paragraphs = array_map(
            fn (string $p) => trim(preg_replace('/\s+/u', ' ', $p) ?? $p),
            preg_split('/\n\s*\n/u', $text) ?: []
        );

        return array_values(array_filter($paragraphs, fn (string $p) => $p !== ''));
    }
}
