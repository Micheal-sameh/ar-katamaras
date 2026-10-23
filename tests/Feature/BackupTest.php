<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Models\DailyReading;
use App\Services\CopticCalendarService;
use App\Services\ReadingStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\Support\FakesKatameros;
use Tests\TestCase;

/** DatabaseMigrations (not RefreshDatabase): SQLite cannot VACUUM inside the transaction RefreshDatabase wraps tests in. */
class BackupTest extends TestCase
{
    use DatabaseMigrations;
    use FakesKatameros;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'readings-backup-'.uniqid();
        config(['readings.backup.directory' => $this->directory, 'readings.backup.keep' => 2]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_backup_is_a_complete_copy_of_the_database_and_old_copies_are_removed(): void
    {
        $today = app(CopticCalendarService::class)->today();
        for ($i = 0; $i < 3; $i++) {
            app(ReadingStore::class)->save($this->sampleDto($today->addDays($i), Language::Arabic));
        }
        File::ensureDirectoryExists($this->directory);
        foreach (['20250101-000000', '20250102-000000', '20250103-000000'] as $stamp) { // older copies
            file_put_contents("{$this->directory}/readings-{$stamp}.sqlite", 'old');
        }

        $this->artisan('readings:backup')->expectsOutputToContain('removed 2 old')->assertSuccessful();

        $files = glob($this->directory.'/readings-*.sqlite');
        $this->assertCount(2, $files);
        $newest = collect($files)->sortDesc()->first();
        $copy = new \PDO('sqlite:'.$newest);
        $this->assertSame(3, (int) $copy->query('select count(*) from daily_readings')->fetchColumn());
        $this->assertSame(3, DailyReading::count());
    }

    public function test_the_newest_copy_is_never_deleted_even_if_keep_is_zero(): void
    {
        config(['readings.backup.keep' => 0]);

        $this->artisan('readings:backup')->assertSuccessful();

        $this->assertCount(1, glob($this->directory.'/readings-*.sqlite'));
    }

    public function test_the_backup_is_scheduled_nightly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'readings:backup'));

        $this->assertNotNull($event);
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertSame('Africa/Cairo', (string) $event->timezone);
    }
}
