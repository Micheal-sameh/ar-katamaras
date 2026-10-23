<?php

namespace App\Http\Controllers;

use App\Services\HealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /** 200 when everything works, 503 when something does not: made for an uptime monitor. */
    public function show(HealthService $health): JsonResponse
    {
        $result = $health->check();

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
