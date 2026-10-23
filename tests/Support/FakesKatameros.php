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
                                    'bookId' => 46,
                                    'verses' => [
                                        ['number' => 4, 'chapter' => 3, 'text' => 'لأنه متى قال واحد: أنا لبولس'],
                                        ['number' => 5, 'chapter' => 3, 'text' => 'فمن هو بولس؟ ومن هو أبلوس؟'],
                                    ],
                                ]],
                                'html' => null,
                            ]],
                        ],
                        [
                            'title' => 'المزمور والإنجيل',
                            'introduction' => null,
                            'readings' => [
                                [
                                    'title' => null,
                                    'introduction' => null,
                                    'conclusion' => null,
                                    'passages' => [[
                                        'bookId' => 19,
                                        'bookTranslation' => 'مزامير',
                                        'ref' => '37:30-31',
                                        'verses' => [['number' => 30, 'chapter' => 37, 'text' => 'فم الصديق يلهج بالحكمة']],
                                    ]],
                                    'html' => null,
                                ],
                                [
                                    'title' => null,
                                    'introduction' => null,
                                    'conclusion' => null,
                                    'passages' => [[
                                        'bookId' => 42,
                                        'bookTranslation' => 'لوقا',
                                        'ref' => '16:1-12',
                                        'verses' => [['number' => 1, 'chapter' => 16, 'text' => 'وقال أيضا لتلاميذه']],
                                    ]],
                                    'html' => null,
                                ],
                            ],
                        ],
                        // As in the real API, the Synaxarium is a sub-section of the Liturgy.
                        [
                            'title' => 'السنكسار',
                            'introduction' => null,
                            'readings' => [[
                                'title' => 'نياحة القديس الأنبا زكريا',
                                'introduction' => null,
                                'conclusion' => null,
                                'passages' => null, // the real API sends null here
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
}
