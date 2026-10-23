<?php

namespace App\Console\Commands;

use App\Services\HealthService;
use Illuminate\Console\Command;

class ReadingsHealth extends Command
{
    protected $signature = 'readings:health {--only=* : database, cache, scheduler, queue or data (default: all)}';

    protected $description = 'Check database, cache, scheduler, queue worker and days stored ahead (exit code 1 when failing)';

    public function handle(HealthService $health): int
    {
        $only = array_filter((array) $this->option('only'));
        $result = $health->check($only === [] ? null : array_values($only));

        foreach ($result['checks'] as $name => $check) {
            $line = sprintf('%-10s %s  %s', $name, $check['ok'] ? 'OK  ' : 'FAIL', $check['detail']);
            $check['ok'] ? $this->line($line) : $this->error($line);
        }

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
