<?php

namespace App\Http\Resources;

use App\DTOs\DailyReadingDto;
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

        return [
            'date' => $dto->date->toDateString(),
            'language' => $dto->language->code(),
            'copticDate' => $payload['copticDate'] + [
                'monthName' => $dto->copticDate->month->label($dto->language),
                'formatted' => $dto->copticDate->format($dto->language),
            ],
            'sections' => $payload['sections'],
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('X-Readings-Source', $this->resource->source->value);
    }
}
