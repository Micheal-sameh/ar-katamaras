<?php

namespace App\Console\Commands;

use App\Enums\Language;
use App\Services\PptImportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportPpt extends Command
{
    protected $signature = 'readings:import-ppt
        {path : A .pptx file, or a directory of .pptx files}
        {--date= : Gregorian date YYYY-MM-DD (otherwise taken from the file name)}
        {--coptic= : Recurring Coptic day as MM-DD, e.g. 02-13}
        {--lang= : ar, en or fr (otherwise taken from the file name, default ar)}';

    protected $description = 'Convert PPTX files to stored text and use them as reading overrides';

    public function handle(PptImportService $importer): int
    {
        $path = $this->argument('path');
        $files = is_dir($path) ? glob(rtrim($path, '/\\').DIRECTORY_SEPARATOR.'*.pptx') : [$path];
        if ($files === [] || $files === false) {
            $this->error('No .pptx files found.');

            return self::FAILURE;
        }

        $language = $this->option('lang') ? Language::fromCode($this->option('lang')) : null;
        $date = $this->option('date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('date'), config('readings.timezone'))
            : null;

        $failed = 0;
        foreach ($files as $file) {
            try {
                $override = $importer->import($file, $language, $date, $this->option('coptic'));
                $state = $override->wasRecentlyCreated ? 'imported' : ($override->wasChanged() ? 'updated' : 'unchanged');
                $this->line(sprintf('%-10s %s', $state, basename($file)));
            } catch (InvalidArgumentException $e) {
                $failed++;
                $this->error(basename($file).': '.$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
