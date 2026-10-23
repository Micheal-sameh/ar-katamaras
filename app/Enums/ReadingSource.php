<?php

namespace App\Enums;

enum ReadingSource: string
{
    case Api = 'api';
    case Cache = 'cache';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'Katameros API',
            self::Cache => 'Cache',
        };
    }
}
