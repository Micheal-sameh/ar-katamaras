<?php

namespace App\Http\Requests;

use App\Enums\Language;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/** GET /api/readings/range?from=YYYY-MM-DD&days=14&lang=ar[&bible=] : a few stored days in one request. */
class RangeReadingsRequest extends ShowReadingRequest
{
    protected function prepareForValidation(): void
    {
        // The parent copies the {date} route parameter; this route has none, so "from" plays that part.
        $this->merge(['date' => $this->query('from')]);
    }

    public function rules(): array
    {
        $supported = array_map(fn (Language $l) => $l->code(), config('readings.languages'));
        $today = CarbonImmutable::now(config('readings.timezone'));

        return [
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today->subDays(config('readings.http.past_days'))->toDateString(),
                'before_or_equal:'.$today->addDays(config('readings.http.future_days'))->toDateString(),
            ],
            'days' => ['required', 'integer', 'min:1', 'max:'.(int) config('readings.range.max_days')],
            'lang' => ['nullable', 'string', Rule::in($supported)],
            'bible' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function days(): int
    {
        return (int) $this->validated('days');
    }
}
