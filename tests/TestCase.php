<?php

namespace MyDatabase\BackupRestore\Tests;

use MyDatabase\BackupRestore\BackupRestoreServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->artisan('migrate', ['--database' => 'testing']);
    }

    protected function getPackageProviders($app): array
    {
        return [
            BackupRestoreServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Backup' => \MyDatabase\BackupRestore\Facades\Backup::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup backup configuration
        $app['config']->set('backup.enabled', true);
        $app['config']->set('backup.driver', 'local');
        $app['config']->set('backup.local.path', sys_get_temp_dir() . '/backup-tests');
        $app['config']->set('backup.encryption.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}