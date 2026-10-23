<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'readings:backup';

    protected $description = 'Write a consistent copy of the SQLite database and delete the oldest copies';

    public function handle(): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            $this->error('Only SQLite is backed up here. For MySQL/PostgreSQL use mysqldump / pg_dump.');

            return self::FAILURE;
        }

        $directory = rtrim((string) config('readings.backup.directory'), '/\\');
        File::ensureDirectoryExists($directory);
        $file = $directory.DIRECTORY_SEPARATOR.'readings-'.now()->format('Ymd-His').'.sqlite';

        try {
            // VACUUM INTO writes a complete, consistent copy while the site keeps running.
            $connection->getPdo()->exec("VACUUM INTO '".str_replace("'", "''", $file)."'");
        } catch (Throwable $e) {
            Log::critical('Readings: database backup failed', ['error' => $e->getMessage()]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $removed = $this->prune($directory);
        $this->info("Backup written: {$file} (".number_format(filesize($file) / 1024, 0).' KB)'.($removed > 0 ? ", removed {$removed} old" : ''));

        return self::SUCCESS;
    }

    private function prune(string $directory): int
    {
        $files = glob($directory.DIRECTORY_SEPARATOR.'readings-*.sqlite') ?: [];
        rsort($files); // names carry the timestamp, so newest first
        $old = array_slice($files, max(1, (int) config('readings.backup.keep')));
        foreach ($old as $path) {
            File::delete($path);
        }

        return count($old);
    }
}
