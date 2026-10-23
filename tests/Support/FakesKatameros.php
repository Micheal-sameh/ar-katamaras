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
}
