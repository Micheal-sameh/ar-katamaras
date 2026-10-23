<?php

namespace App\Models;

use App\Enums\Language;
use Illuminate\Database\Eloquent\Model;

class ReadingOverride extends Model
{
    protected $fillable = ['date', 'coptic_key', 'language', 'payload', 'source_file', 'file_hash'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'language' => Language::class,
            'payload' => 'array',
        ];
    }
}
