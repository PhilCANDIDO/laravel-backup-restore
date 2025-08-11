<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:uploads {--type=manual : Backup frequency type (manual, daily, weekly, monthly)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup user uploads and storage files (storage/app/public)';

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
        
        $this->info('Starting uploads backup...');
        $this->info('Type: ' . $type);
        $this->info('Directory to backup: storage/app/public');
        
        try {
            $backupService = new BackupService();
            $backupLog = $backupService->backupUploads($type);
            
            $this->info('Uploads backup completed successfully!');
            $this->table(
                ['Property', 'Value'],
                [
                    ['Filename', $backupLog->filename],
                    ['Size', $backupLog->formatted_size],
                    ['Duration', $backupLog->duration_seconds . ' seconds'],
                    ['Location', $backupLog->location],
                ]
            );
            
            Log::info('Uploads backup completed via console command', [
                'type' => $type,
                'filename' => $backupLog->filename,
            ]);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Uploads backup failed: ' . $e->getMessage());
            
            Log::error('Uploads backup failed via console command', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return 1;
        }
    }
}
