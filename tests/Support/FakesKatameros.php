<?php

namespace Tests\Support;

use App\DTOs\DailyReadingDto;
use App\DTOs\PassageDto;
use App\DTOs\ReadingDto;
use App\DTOs\SectionDto;
use App\DTOs\SubSectionDto;
use App\DTOs\VerseDto;
use App\Enums\Language;
use App\Enums\ReadingSource;
use App\Enums\ReadingType;
use App\Enums\SectionType;
use App\Services\CopticCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use ZipArchive;

trait FakesKatameros
{
    /** The Katameros response shape, as documented for the API. */
    protected function katamerosPayload(string $copticDate = '13/2/1743'): array
    {
        return [
            'copticDate' => $copticDate,
            'bible' => ['id' => 1, 'name' => 'Van Dyck'],
            'bibles' => [],
            'sections' => [
                [
                    'title' => 'قداس',
                    'subSections' => [
                        [
                            'title' => 'البولس',
                            'introduction' => null,
                            'readings' => [[
                                'title' => 'البولس',
                                'introduction' => 'من رسالة معلمنا بولس الرسول الأولى إلى أهل كورنثوس',
                                'conclusion' => 'نعمة الله الآب تكون مع جميعكم. آمين.',
                                'passages' => [[
                                    'bookTranslation' => 'كورنثوس الأولى',
                                    'ref' => '١ كورنثوس ٣: ٤–٢٣',
                                    'verses' => [
                                        ['number' => 4, 'text' => 'لأنه متى قال واحد: أنا لبولس'],
                                        ['number' => 5, 'text' => 'فمن هو بولس؟ ومن هو أبلوس؟'],
                                    ],
                                ]],
                                'html' => null,
                            ]],
                        ],
                        [
                            'title' => 'المزمور والإنجيل',
                            'introduction' => null,
                            'readings' => [[
                                'title' => 'الإنجيل',
                                'introduction' => null,
                                'conclusion' => null,
                                'passages' => [[
                                    'bookTranslation' => 'لوقا',
                                    'ref' => 'لوقا ١٠: ٢٥',
                                    'verses' => [['number' => 25, 'text' => 'وإذا ناموسي قام يجربه']],
                                ]],
                                'html' => null,
                            ]],
                        ],
                        // As in the real API, the Synaxarium is a sub-section of the Liturgy.
                        [
                            'title' => 'السنكسار',
                            'introduction' => null,
                            'readings' => [[
                                'title' => 'نياحة القديس الأنبا زكريا',
                                'introduction' => null,
                                'conclusion' => null,
                                'passages' => [],
                                'html' => '<p>في مثل هذا اليوم تنيّح القديس.</p><script>alert(1)</script><p>بركة صلواته <b>تكون</b> معنا.&nbsp;آمين.</p>',
                            ]],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function fakeKatameros(?array $payload = null): void
    {
        Http::fake(['api.katameros.app/*' => Http::response($payload ?? $this->katamerosPayload())]);
    }

    /** A small valid day, for seeding the store without touching the API. */
    protected function sampleDto(CarbonImmutable $date, Language $language, string $text = 'نص تجريبي'): DailyReadingDto
    {
        return new DailyReadingDto(
            $date,
            $language,
            app(CopticCalendarService::class)->fromGregorian($date),
            [new SectionDto(SectionType::Liturgy, 'قداس', [
                new SubSectionDto(ReadingType::Pauline, 'البولس', null, [
                    new ReadingDto('البولس', null, null, [new PassageDto(null, 'ref', [new VerseDto(1, $text)])]),
                ]),
            ])],
            ReadingSource::Api,
        );
    }

    /** Writes a minimal .pptx (one slide per entry, one paragraph per line) and returns its path. */
    protected function makePptx(string $filename, array $slides): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'readings-test-'.uniqid();
        mkdir($dir);
        $path = $dir.DIRECTORY_SEPARATOR.$filename;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($slides as $i => $lines) {
            $paragraphs = implode('', array_map(
                fn (string $line) => '<a:p><a:r><a:t>'.htmlspecialchars($line, ENT_XML1).'</a:t></a:r></a:p>',
                $lines,
            ));
            $zip->addFromString(
                'ppt/slides/slide'.($i + 1).'.xml',
                '<?xml version="1.0" encoding="UTF-8"?><p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody>'.$paragraphs.'</p:txBody></p:sp></p:spTree></p:cSld></p:sld>',
            );
        }
        $zip->close();

        return $path;
    }
}
