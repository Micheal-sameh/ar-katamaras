<?php

namespace App\Http\Requests;

use App\Enums\Language;
use App\Services\BibleCatalog;
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
            // Checked against the allow-list for the chosen language in withValidator().
            'bible' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('bible')) {
                return;
            }
            $language = Language::fromCode((string) $this->input('lang')) ?? config('readings.default_language');
            if (! app(BibleCatalog::class)->isAllowed($language, (int) $this->input('bible'))) {
                $validator->errors()->add('bible', 'This Bible version is not available for the chosen language.');
            }
        });
    }

    public function bibleId(): int
    {
        return (int) ($this->validated('bible') ?? 0);
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
