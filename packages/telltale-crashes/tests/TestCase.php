<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes\Tests;

use ArtisanBuild\TelltaleClient\TelltaleClientServiceProvider;
use ArtisanBuild\TelltaleCrashes\TelltaleCrashesServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $databasePath;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        // Deliberately register crashes first: listener wiring cannot depend on provider order.
        return [TelltaleCrashesServiceProvider::class, TelltaleClientServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $this->databasePath = sys_get_temp_dir().'/telltale-crashes-'.bin2hex(random_bytes(8)).'.sqlite';
        $config = $app->make(Repository::class);
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('telltale.database', $this->databasePath);
        $config->set('telltale.queue.auto_dispatch', false);
        $config->set('queue.connections.database.driver', 'database');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-shm', '-wal'] as $suffix) {
            if (is_file($this->databasePath.$suffix)) {
                unlink($this->databasePath.$suffix);
            }
        }
    }
}
