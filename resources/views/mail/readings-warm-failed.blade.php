The monthly readings pre-cache did not finish cleanly.

Problem: {{ $reason }}
Range:   {{ $range }}
@if (count($missing) > 0)

{{ count($missing) }} day(s) are still not stored (language:date):
@foreach (array_slice($missing, 0, 40) as $item)
  - {{ $item }}
@endforeach
@if (count($missing) > 40)
  ... and {{ count($missing) - 40 }} more
@endif
@endif
@if (count($errors) > 0)

First errors:
@foreach (array_slice($errors, 0, 5) as $error)
  - {{ $error }}
@endforeach
@endif

What it means: visitors asking for those days will wait for a live fetch from the Katameros API,
and will see "readings unavailable" if that API is down at that moment.

What to do: check that the server can reach https://api.katameros.app, then run
  php artisan readings:warm-month
(in Docker: docker compose exec app php artisan readings:warm-month)
Days that are already stored are skipped, so it is safe to run again.
