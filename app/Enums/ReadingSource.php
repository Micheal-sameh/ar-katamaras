<?php

namespace App\Enums;

enum ReadingSource: string
{
    case Api = 'api';
    case Ppt = 'ppt';
    case Cache = 'cache';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'Katameros API',
            self::Ppt => 'PPT override',
            self::Cache => 'Cache',
        };
    }
}
