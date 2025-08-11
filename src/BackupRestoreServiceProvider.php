<?php

namespace MyDatabase\BackupRestore;

use Illuminate\Support\ServiceProvider;
use MyDatabase\BackupRestore\Commands\BackupAll;
use MyDatabase\BackupRestore\Commands\BackupDatabase;
use MyDatabase\BackupRestore\Commands\BackupFiles;
use MyDatabase\BackupRestore\Commands\BackupUploads;
use MyDatabase\BackupRestore\Commands\CleanOldBackups;
use MyDatabase\BackupRestore\Commands\CreateStagingEnvironment;
use MyDatabase\BackupRestore\Commands\DecryptBackup;
use MyDatabase\BackupRestore\Commands\DownloadBackup;
use MyDatabase\BackupRestore\Commands\ListBackups;
use MyDatabase\BackupRestore\Commands\RestoreBackup;
use MyDatabase\BackupRestore\Services\BackupService;

class BackupRestoreServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__.'/../config/backup.php' => config_path('backup.php'),
            ], 'backup-config');

            // Publish migrations
            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'backup-migrations');

            // Publish scripts
            $this->publishes([
                __DIR__.'/../scripts/' => base_path('scripts'),
            ], 'backup-scripts');

            // Publish stubs for customization
            $this->publishes([
                __DIR__.'/../stubs/' => base_path('stubs/backup-restore'),
            ], 'backup-stubs');

            // Register commands
            $this->commands([
                BackupAll::class,
                BackupDatabase::class,
                BackupFiles::class,
                BackupUploads::class,
                CleanOldBackups::class,
                CreateStagingEnvironment::class,
                DecryptBackup::class,
                DownloadBackup::class,
                ListBackups::class,
                RestoreBackup::class,
            ]);
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load config
        $this->mergeConfigFrom(
            __DIR__.'/../config/backup.php', 'backup'
        );
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        // Register BackupService as singleton
        $this->app->singleton(BackupService::class, function ($app) {
            return new BackupService();
        });

        // Register facade
        $this->app->alias(BackupService::class, 'backup');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            BackupService::class,
            'backup',
        ];
    }
}