<?php

namespace App\Http\Middleware;

use App\Services\ChurchAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the readings to people who were given a church access code (see ChurchAccess for how codes are set).
 * The apps send it in the X-Church-Code header. With no codes configured, everything is open.
 * Wrong guesses are limited per IP, so codes cannot be tried out by a script.
 */
class RequireChurchCode
{
    public function __construct(private readonly ChurchAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->access->enabled()) {
            return $next($request);
        }

        if ($this->access->acceptsApp((string) $request->header('X-App-Key', ''))) {
            return $next($request); // the Android / Windows apps: only the website asks for a code
        }

        $given = (string) $request->header('X-Church-Code', '');
        if ($this->access->accepts($given)) {
            return $next($request);
        }

        $key = 'church-code:'.$request->ip();
        $max = max(1, (int) config('readings.access.max_failures_per_minute'));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json(['message' => 'Too many wrong codes. Try again in a minute.', 'code' => 'church_code_blocked'], 429, [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }

        if (trim($given) !== '') { // no code at all is normal on the first visit; only a wrong code counts as a miss
            RateLimiter::hit($key, 60);
        }

        return response()->json(['message' => 'The church access code is required.', 'code' => 'church_code_required'], 401);
    }
}
