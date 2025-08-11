<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:files {--type=manual : Backup frequency type (manual, daily, weekly, monthly)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup application files (app, config, database, resources, routes)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!config('backup.enabled')) {
            $this->error('Backup is disabled. Enable it in the configuration.');
            return 1;
        }

        $type = $this->option('type');
        
        $this->info('Starting application files backup...');
        $this->info('Type: ' . $type);
        $this->info('Directories to backup: app, config, database, resources, routes');
        
        try {
            $backupService = new BackupService();
            $backupLog = $backupService->backupFiles($type);
            
            $this->info('Application files backup completed successfully!');
            $this->table(
                ['Property', 'Value'],
                [
                    ['Filename', $backupLog->filename],
                    ['Size', $backupLog->formatted_size],
                    ['Duration', $backupLog->duration_seconds . ' seconds'],
                    ['Location', $backupLog->location],
                ]
            );
            
            Log::info('Application files backup completed via console command', [
                'type' => $type,
                'filename' => $backupLog->filename,
            ]);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Application files backup failed: ' . $e->getMessage());
            
            Log::error('Application files backup failed via console command', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return 1;
        }
    }
}
