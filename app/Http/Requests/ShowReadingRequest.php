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

        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(2)->toDateString()],
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
