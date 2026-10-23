<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cache-Control + ETag for successful reading responses. Errors are never marked cacheable. */
class ReadingsHttpCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 200) {
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $response->setPublic();
        $response->setMaxAge((int) config('readings.http.max_age'));
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request); // turns the response into a 304 when If-None-Match matches

        return $response;
    }
}
