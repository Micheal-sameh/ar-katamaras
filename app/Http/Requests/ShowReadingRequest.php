<?php

namespace App\Http\Requests;

use App\Enums\Language;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['date' => $this->route('date')]);
    }

    public function rules(): array
    {
        $supported = array_map(fn (Language $l) => $l->code(), config('readings.languages'));
        $today = CarbonImmutable::now(config('readings.timezone'));

        return [
            // Only a window around today: stops anyone from making the server fetch arbitrary dates.
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today->subDays(config('readings.http.past_days'))->toDateString(),
                'before_or_equal:'.$today->addDays(config('readings.http.future_days'))->toDateString(),
            ],
            'lang' => ['nullable', 'string', Rule::in($supported)],
        ];
    }

    public function readingDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('date'), config('readings.timezone'));
    }

    public function language(): Language
    {
        $code = $this->validated('lang');

        return $code ? Language::fromCode($code) : config('readings.default_language');
    }
}
