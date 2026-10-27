<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/** GET /api/readings/access: the apps call it to check a typed code (the RequireChurchCode middleware does the checking). */
class AccessCheckController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
