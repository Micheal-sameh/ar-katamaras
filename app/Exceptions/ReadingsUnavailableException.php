<?php

namespace App\Exceptions;

use App\Enums\Language;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** No stored copy exists and the remote source cannot be reached. Rendered as HTTP 503. */
class ReadingsUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $date, public readonly Language $language, ?\Throwable $previous = null)
    {
        parent::__construct("Readings for {$date} ({$language->code()}) are unavailable.", 0, $previous);
    }

    public function render(): JsonResponse
    {
        $messages = [
            Language::Arabic->value => 'القراءات غير متاحة حاليًا لهذا اليوم. يرجى المحاولة لاحقًا.',
            Language::French->value => 'Les lectures de ce jour ne sont pas disponibles pour le moment. Veuillez réessayer plus tard.',
            Language::English->value => 'Readings for this day are not available right now. Please try again later.',
        ];

        return response()->json(
            ['message' => $messages[$this->language->value], 'code' => 'readings_unavailable'],
            503,
            ['Cache-Control' => 'no-store', 'Retry-After' => '300'],
        );
    }
}
