<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:database {--type=manual : Backup frequency type (manual, daily, weekly, monthly)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup the database to configured storage location';

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
        
        $this->info('Starting database backup...');
        $this->info('Type: ' . $type);
        
        try {
            $backupService = new BackupService();
            $backupLog = $backupService->backupDatabase($type);
            
            $this->info('Database backup completed successfully!');
            $this->table(
                ['Property', 'Value'],
                [
                    ['Filename', $backupLog->filename],
                    ['Size', $backupLog->formatted_size],
                    ['Duration', $backupLog->duration_seconds . ' seconds'],
                    ['Location', $backupLog->location],
                ]
            );
            
            Log::info('Database backup completed via console command', [
                'type' => $type,
                'filename' => $backupLog->filename,
            ]);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Database backup failed: ' . $e->getMessage());
            
            Log::error('Database backup failed via console command', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return 1;
        }
    }
}
