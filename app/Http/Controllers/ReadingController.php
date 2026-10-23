<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowReadingRequest;
use App\Http\Resources\DailyReadingResource;
use App\Services\ReadingService;

class ReadingController extends Controller
{
    public function show(ShowReadingRequest $request, ReadingService $readings): DailyReadingResource
    {
        return new DailyReadingResource(
            $readings->getForDate($request->readingDate(), $request->language(), $request->bibleId()),
        );
    }
}
