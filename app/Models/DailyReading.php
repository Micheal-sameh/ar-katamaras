<?php

namespace App\Models;

use App\Enums\Language;
use App\Enums\ReadingSource;
use Illuminate\Database\Eloquent\Model;

class DailyReading extends Model
{
    protected $fillable = ['date', 'language', 'bible_id', 'coptic_date', 'payload', 'source', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'language' => Language::class,
            'payload' => 'array',
            'source' => ReadingSource::class,
            'fetched_at' => 'datetime',
        ];
    }
}
