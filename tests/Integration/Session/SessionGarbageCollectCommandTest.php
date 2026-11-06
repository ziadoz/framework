<?php

namespace Illuminate\Tests\Integration\Session;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SessionGarbageCollectCommandTest extends TestCase
{
    protected $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/laravel-session-gc-'.uniqid();

        (new Filesystem)->ensureDirectoryExists($this->path);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->path);

        parent::tearDown();
    }

    public function testGarbageCollectorRemovesExpiredSessions()
    {
        $this->app['config']->set('session.driver', 'file');
        $this->app['config']->set('session.files', $this->path);
        $this->app['config']->set('session.lifetime', 120);

        touch($expired = $this->path.'/expired', time() - 7201);
        touch($active = $this->path.'/active');

        $this->artisan('session:garbage-collect')
            ->expectsOutputToContain('Session garbage collector ran successfully.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($expired);
        $this->assertFileExists($active);
    }

    #[DataProvider('driversThatExpireSessions')]
    public function testGarbageCollectorIsSkippedForDriversThatExpireSessions(string $driver)
    {
        $this->app['config']->set('session.driver', $driver);

        $this->artisan('session:garbage-collect')
            ->expectsOutputToContain("The [{$driver}] session driver does not require garbage collection.")
            ->assertSuccessful();
    }

    public static function driversThatExpireSessions(): array
    {
        return [
            'redis' => ['redis'],
            'cookie' => ['cookie'],
            'null' => ['null'],
        ];
    }
}
