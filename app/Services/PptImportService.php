<?php

namespace App\Services;

use App\DTOs\PassageDto;
use App\DTOs\ReadingDto;
use App\DTOs\SectionDto;
use App\DTOs\SubSectionDto;
use App\DTOs\VerseDto;
use App\Enums\Language;
use App\Enums\ReadingType;
use App\Enums\SectionType;
use App\Models\ReadingOverride;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use ZipArchive;

/**
 * Converts a .pptx into stored text (a reading override). The PPTX is read once, on the server,
 * and is never served to clients.
 *
 * File naming (the target is taken from the file name unless passed explicitly):
 *   2026-10-23.pptx          a Gregorian date
 *   coptic-02-13.pptx        a recurring Coptic day (month-day)
 *   a trailing _ar / _en / _fr picks the language (default: Arabic)
 *
 * Slide convention: a slide whose first line names a section (القداس, السنكسار ...) or a reading
 * (البولس, الكاثوليكون ...) starts that section/reading. Every other line is text of the current reading.
 */
class PptImportService
{
    private const MAX_SLIDES = 500;

    private const MAX_SLIDE_BYTES = 5_000_000;

    public function __construct(
        private readonly ReadingService $readings,
    ) {}

    public function import(
        string $path,
        ?Language $language = null,
        ?CarbonImmutable $date = null,
        ?string $copticKey = null,
    ): ReadingOverride {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $target = $this->targetFromFilename(basename($path));
        $language ??= $target['language'] ?? Language::Arabic;
        $date ??= $target['date'];
        $copticKey ??= $target['copticKey'];

        if ($date === null && $copticKey === null) {
            throw new InvalidArgumentException('Cannot tell which day this file is for. Name it 2026-10-23.pptx or coptic-02-13.pptx, or pass --date / --coptic.');
        }

        $sections = $this->toSections($this->extractSlides($path));
        if ($sections === []) {
            throw new InvalidArgumentException('The presentation contains no readable text.');
        }

        $hash = hash_file('sha256', $path);
        $keys = $date !== null
            ? ['date' => $date->toDateString(), 'language' => $language->value]
            : ['coptic_key' => $copticKey, 'language' => $language->value];

        $override = ReadingOverride::query()->firstOrNew($keys);
        if ($override->exists && $override->file_hash === $hash) {
            return $override; // unchanged: nothing to invalidate
        }

        $override->fill([
            'payload' => ['sections' => array_map(fn (SectionDto $s) => $s->toArray(), $sections)],
            'source_file' => basename($path),
            'file_hash' => $hash,
        ])->save();

        $this->readings->refreshOverride($override); // rewrites stored days and forgets their cache

        return $override;
    }

    /** @return array{language: ?Language, date: ?CarbonImmutable, copticKey: ?string} */
    public function targetFromFilename(string $filename): array
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $language = preg_match('/[-_](ar|en|fr)$/i', $name, $m) ? Language::fromCode($m[1]) : null;

        $date = null;
        $copticKey = null;
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $name, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $date = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, config('readings.timezone'));
        } elseif (preg_match('/coptic[-_](\d{1,2})[-_](\d{1,2})/i', $name, $m)) {
            $copticKey = sprintf('%02d-%02d', (int) $m[1], (int) $m[2]);
        }

        return ['language' => $language, 'date' => $date, 'copticKey' => $copticKey];
    }

    /**
     * Text of every slide, in slide order. Each slide is a list of non-empty paragraphs.
     *
     * @return list<list<string>>
     */
    public function extractSlides(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('Not a valid .pptx file.');
        }

        try {
            $slideNames = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                    $slideNames[(int) $m[1]] = $name;
                }
            }
            ksort($slideNames);
            if (count($slideNames) > self::MAX_SLIDES) {
                throw new InvalidArgumentException('Too many slides.');
            }

            $slides = [];
            foreach ($slideNames as $name) {
                $stat = $zip->statName($name);
                if ($stat === false || $stat['size'] > self::MAX_SLIDE_BYTES) {
                    throw new InvalidArgumentException("Slide {$name} is too large.");
                }
                $lines = $this->slideParagraphs((string) $zip->getFromName($name));
                if ($lines !== []) {
                    $slides[] = $lines;
                }
            }

            return $slides;
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function slideParagraphs(string $xml): array
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR); // no entity expansion
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        $lines = [];
        foreach ($xpath->query('//a:p') as $paragraph) {
            $text = '';
            foreach ($xpath->query('a:r/a:t | a:br | a:fld/a:t', $paragraph) as $node) {
                $text .= $node->nodeName === 'a:br' ? ' ' : $node->textContent;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /**
     * @param  list<list<string>>  $slides
     * @return list<SectionDto>
     */
    private function toSections(array $slides): array
    {
        /** @var array<int, array{type: SectionType, title: string, subs: array<int, array{type: ReadingType, title: string, lines: list<string>}>}> $sections */
        $sections = [];
        $section = -1; // index into $sections (-1 = none yet)
        $sub = -1;     // index into the current section's subs (-1 = none yet)

        $start = function (SectionType $type, string $title) use (&$sections, &$section, &$sub): void {
            $sections[] = ['type' => $type, 'title' => $title, 'subs' => []];
            $section = array_key_last($sections);
            $sub = -1;
        };

        foreach ($slides as $lines) {
            $first = $lines[0];
            $sectionType = SectionType::fromTitle($first);
            $readingType = ReadingType::fromTitle($first);
            $isHeading = mb_strlen($first) <= 60;

            if ($isHeading && $sectionType !== SectionType::Other) {
                $start($sectionType, $first);
                if ($sectionType === SectionType::Synaxarium) { // its text is the reading itself
                    $sections[$section]['subs'][] = ['type' => ReadingType::Synaxarium, 'title' => $first, 'lines' => []];
                    $sub = array_key_last($sections[$section]['subs']);
                }
                array_shift($lines);
            } elseif ($isHeading && $readingType !== ReadingType::Other) {
                if ($section < 0) {
                    $start(SectionType::Liturgy, SectionType::Liturgy->label(Language::Arabic));
                }
                $sections[$section]['subs'][] = ['type' => $readingType, 'title' => $first, 'lines' => []];
                $sub = array_key_last($sections[$section]['subs']);
                array_shift($lines);
            }

            if ($lines === []) {
                continue;
            }
            if ($section < 0) {
                $start(SectionType::Liturgy, SectionType::Liturgy->label(Language::Arabic));
            }
            if ($sub < 0) {
                $sections[$section]['subs'][] = ['type' => ReadingType::Other, 'title' => '', 'lines' => []];
                $sub = array_key_last($sections[$section]['subs']);
            }
            array_push($sections[$section]['subs'][$sub]['lines'], ...$lines);
        }

        $result = [];
        foreach ($sections as $s) {
            $subSections = [];
            foreach ($s['subs'] as $sb) {
                if ($sb['lines'] === []) {
                    continue;
                }
                $subSections[] = new SubSectionDto($sb['type'], $sb['title'], null, [$this->toReading($s['type'], $sb['title'], $sb['lines'])]);
            }
            if ($subSections !== []) {
                $result[] = new SectionDto($s['type'], $s['title'], $subSections);
            }
        }

        return $result;
    }

    /** @param list<string> $lines */
    private function toReading(SectionType $section, string $title, array $lines): ReadingDto
    {
        if ($section === SectionType::Synaxarium) {
            return new ReadingDto($title, null, null, [], $lines);
        }

        $verses = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\d{1,3})\s*[\.\):\-–]?\s+(.+)$/u', $line, $m)) {
                $verses[] = new VerseDto((int) $m[1], $m[2]);
            } else {
                $verses[] = new VerseDto(0, $line);
            }
        }

        return new ReadingDto($title, null, null, [new PassageDto(null, '', $verses)]);
    }
}
