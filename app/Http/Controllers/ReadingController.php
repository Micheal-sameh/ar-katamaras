<?php

namespace App\Http\Controllers;

use App\Http\Requests\RangeReadingsRequest;
use App\Http\Requests\ShowReadingRequest;
use App\Http\Resources\DailyReadingResource;
use App\Http\Resources\ReadingRangeResource;
use App\Services\ReadingLookupService;
use App\Services\ReadingRangeService;

class ReadingController extends Controller
{
    public function show(ShowReadingRequest $request, ReadingLookupService $readings): DailyReadingResource
    {
        return new DailyReadingResource(
            $readings->get($request->readingDate(), $request->language(), $request->bibleId()),
        );
    }

    public function range(RangeReadingsRequest $request, ReadingRangeService $ranges): ReadingRangeResource
    {
        return new ReadingRangeResource(
            $ranges->stored($request->readingDate(), $request->days(), $request->language(), $request->bibleId()),
        );
    }
}
