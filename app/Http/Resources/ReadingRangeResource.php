<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The body of GET /api/readings/range: every stored day in the same shape as a single-day response. */
class ReadingRangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource['from']->toDateString(),
            'days' => array_map(fn ($day) => (new DailyReadingResource($day))->resolve($request), $this->resource['days']),
            'missing' => $this->resource['missing'],
        ];
    }
}
