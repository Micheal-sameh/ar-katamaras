<?php

namespace App\Http\Resources;

use App\DTOs\DailyReadingDto;
use App\Enums\ReadingType;
use App\Services\BibleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property DailyReadingDto $resource */
class DailyReadingResource extends JsonResource
{
    /** The body depends only on the day's content (never on where it came from), so its ETag stays stable. */
    public function toArray(Request $request): array
    {
        $dto = $this->resource;
        $payload = $dto->toPayload();
        $catalog = app(BibleCatalog::class);

        foreach ($payload['sections'] as &$section) {
            foreach ($section['subSections'] as &$subSection) {
                foreach ($subSection['readings'] as &$reading) {
                    // "Psalm" / "Gospel" label for sub-sections that hold more than one reading.
                    $reading['label'] = ReadingType::tryFrom((string) $reading['type'])?->label($dto->language);
                }
            }
        }
        unset($section, $subSection, $reading);

        $bibleId = $dto->bibleId > 0 ? $dto->bibleId : $catalog->defaultId($dto->language);

        return [
            'date' => $dto->date->toDateString(),
            'language' => $dto->language->code(),
            'copticDate' => $payload['copticDate'] + [
                'monthName' => $dto->copticDate->month->label($dto->language),
                'formatted' => $dto->copticDate->format($dto->language),
            ],
            'bible' => ['id' => $bibleId, 'name' => $catalog->available($dto->language)[$bibleId] ?? null],
            'bibles' => $catalog->list($dto->language), // the versions the visitor can pick from
            'sections' => $payload['sections'],
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('X-Readings-Source', $this->resource->source->value);
    }
}
