<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:all {--type=manual : Backup frequency type (manual, daily, weekly, monthly)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup everything: database, application files, and uploads';

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
        
        $this->info('Starting complete backup...');
        $this->info('Type: ' . $type);
        $this->newLine();
        
        $backupService = new BackupService();
        $results = [];
        $hasErrors = false;
        
        // Backup database
        $this->info('Backing up database...');
        try {
            $backupLog = $backupService->backupDatabase($type);
            $results['database'] = [
                'status' => 'Success',
                'filename' => $backupLog->filename,
                'size' => $backupLog->formatted_size,
                'duration' => $backupLog->duration_seconds . 's',
            ];
            $this->info('   Database backup completed');
        } catch (\Exception $e) {
            $hasErrors = true;
            $results['database'] = [
                'status' => 'Failed',
                'error' => $e->getMessage(),
            ];
            $this->error('   Database backup failed: ' . $e->getMessage());
        }
        
        $this->newLine();
        
        // Backup application files
        $this->info('Backing up application files...');
        try {
            $backupLog = $backupService->backupFiles($type);
            $results['files'] = [
                'status' => 'Success',
                'filename' => $backupLog->filename,
                'size' => $backupLog->formatted_size,
                'duration' => $backupLog->duration_seconds . 's',
            ];
            $this->info('   Application files backup completed');
        } catch (\Exception $e) {
            $hasErrors = true;
            $results['files'] = [
                'status' => 'Failed',
                'error' => $e->getMessage(),
            ];
            $this->error('   Application files backup failed: ' . $e->getMessage());
        }
        
        $this->newLine();
        
        // Backup uploads
        $this->info('Backing up uploads...');
        try {
            $backupLog = $backupService->backupUploads($type);
            $results['uploads'] = [
                'status' => 'Success',
                'filename' => $backupLog->filename,
                'size' => $backupLog->formatted_size,
                'duration' => $backupLog->duration_seconds . 's',
            ];
            $this->info('   Uploads backup completed');
        } catch (\Exception $e) {
            $hasErrors = true;
            $results['uploads'] = [
                'status' => 'Failed',
                'error' => $e->getMessage(),
            ];
            $this->error('   Uploads backup failed: ' . $e->getMessage());
        }
        
        $this->newLine();
        
        // Display summary
        $this->info('Backup Summary:');
        $this->info('----------------------------------------');
        
        foreach ($results as $type => $result) {
            $this->info(ucfirst($type) . ': ' . $result['status']);
            if (isset($result['filename'])) {
                $this->info('  File: ' . $result['filename']);
                $this->info('  Size: ' . $result['size']);
                $this->info('  Duration: ' . $result['duration']);
            } elseif (isset($result['error'])) {
                $this->error('  Error: ' . $result['error']);
            }
        }
        
        $this->newLine();
        
        if ($hasErrors) {
            $this->warn('Complete backup finished with errors');
            Log::warning('Complete backup finished with errors', $results);
            return 1;
        } else {
            $this->info('Complete backup completed successfully!');
            Log::info('Complete backup completed successfully', $results);
            return 0;
        }
    }
}
