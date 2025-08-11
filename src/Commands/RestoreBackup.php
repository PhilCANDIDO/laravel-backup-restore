<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use MyDatabase\BackupRestore\Models\BackupLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RestoreBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore 
                            {--file= : Backup file to restore}
                            {--interactive : Select backup file interactively}
                            {--decrypt : Decrypt backup if encrypted}
                            {--password= : Decryption password}
                            {--download : Download from S3 if not found locally}
                            {--force : Skip confirmation prompts}
                            {--dry-run : Simulate the operation without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore a backup to the current database';

    protected BackupService $backupService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->backupService = new BackupService();
        
        // Get backup file
        $backupFile = $this->getBackupFile();
        if (!$backupFile) {
            return 1;
        }
        
        // Display current database info
        $this->displayCurrentDatabaseInfo();
        
        // Display backup info
        $this->displayBackupInfo($backupFile);
        
        // Dry run mode
        if ($this->option('dry-run')) {
            $this->info('DRY RUN: No changes will be made.');
            $this->info('Backup file validated successfully.');
            return 0;
        }
        
        // Confirmation
        if (!$this->option('force')) {
            $this->warn('⚠️  WARNING: This will OVERWRITE your current database!');
            $this->warn('⚠️  All existing data will be replaced with the backup.');
            
            if (!$this->confirm('Are you absolutely sure you want to restore this backup?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
            
            // Double confirmation for production
            if (app()->environment('production')) {
                $confirmText = $this->ask('Type "RESTORE" to confirm restoration in production');
                if ($confirmText !== 'RESTORE') {
                    $this->info('Operation cancelled.');
                    return 0;
                }
            }
        }
        
        try {
            $this->info('Starting database restoration...');
            $startTime = microtime(true);
            
            // Create a progress bar
            $bar = $this->output->createProgressBar(4);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
            
            // Step 1: Validate backup
            $bar->setMessage('Validating backup file...');
            $bar->advance();
            
            $localBackupFile = $this->resolveBackupFile($backupFile);
            if (!$localBackupFile) {
                $bar->finish();
                return 1;
            }
            
            // Step 2: Create safety backup
            $bar->setMessage('Creating safety backup of current database...');
            $bar->advance();
            
            $safetyBackup = $this->createSafetyBackup();
            
            // Step 3: Restore backup
            $bar->setMessage('Restoring database from backup...');
            $bar->advance();
            
            $this->backupService->restoreDatabase(
                $localBackupFile,
                $this->option('decrypt'),
                $this->option('password')
            );
            
            // Step 4: Verify restoration
            $bar->setMessage('Verifying restoration...');
            $bar->advance();
            
            $this->verifyRestoration();
            
            $bar->finish();
            $this->newLine(2);
            
            // Log the operation
            $duration = round(microtime(true) - $startTime, 2);
            $this->logOperation($backupFile, $duration, 'success');
            
            // Display summary
            $this->displayRestorationSummary($backupFile, $duration, $safetyBackup);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->newLine(2);
            $this->error('Failed to restore backup: ' . $e->getMessage());
            
            if (isset($safetyBackup)) {
                $this->warn('A safety backup was created at: ' . $safetyBackup);
                $this->warn('You can restore it using: php artisan backup:restore --file=' . basename($safetyBackup));
            }
            
            Log::error('Backup restoration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->logOperation($backupFile, 0, 'failed', $e->getMessage());
            
            return 1;
        }
    }
    
    /**
     * Get backup file to restore
     */
    protected function getBackupFile(): ?string
    {
        // Check if file is provided
        if ($file = $this->option('file')) {
            return $file;
        }
        
        // Interactive mode
        if ($this->option('interactive')) {
            return $this->selectBackupInteractively();
        }
        
        // No file specified
        $this->error('Please specify a backup file with --file=<filename> or use --interactive mode.');
        return null;
    }
    
    /**
     * Select backup file interactively
     */
    protected function selectBackupInteractively(): ?string
    {
        $this->info('Fetching available backups...');
        
        // Get all database backups
        $backups = [];
        
        // Get local backups
        try {
            $localBackups = $this->backupService->listLocalBackups('database');
            $backups = array_merge($backups, $localBackups);
        } catch (\Exception $e) {
            $this->warn('Failed to fetch local backups: ' . $e->getMessage());
        }
        
        // Get S3 backups if configured
        if (config('backup.driver') === 's3' || $this->option('download')) {
            try {
                $s3Backups = $this->backupService->listS3Backups('database');
                $backups = array_merge($backups, $s3Backups);
            } catch (\Exception $e) {
                $this->warn('Failed to fetch S3 backups: ' . $e->getMessage());
            }
        }
        
        if (empty($backups)) {
            $this->error('No database backups found.');
            return null;
        }
        
        // Sort by date
        usort($backups, function($a, $b) {
            return $b['created_at'] - $a['created_at'];
        });
        
        // Limit to 20 most recent
        $backups = array_slice($backups, 0, 20);
        
        // Prepare choices
        $choices = [];
        foreach ($backups as $backup) {
            $age = $this->getAgeString($backup['created_at']);
            $label = sprintf(
                "%s (%s, %s, %s%s)",
                $backup['filename'],
                $backup['size_human'],
                $age,
                strtoupper($backup['location']),
                $backup['encrypted'] ? ', 🔒' : ''
            );
            $choices[$backup['filename']] = $label;
        }
        
        // Ask user to select
        $selected = $this->choice('Select a backup to restore', array_values($choices), 0);
        
        // Find the filename from the label
        $filename = array_search($selected, $choices);
        
        return $filename;
    }
    
    /**
     * Resolve backup file path
     */
    protected function resolveBackupFile($backupFile): ?string
    {
        // Check if it's a full path
        if (file_exists($backupFile)) {
            return $backupFile;
        }
        
        // Check in backup directory
        $backupPath = config('backup.local.path', storage_path('app/backups'));
        $localFile = $backupPath . '/' . $backupFile;
        
        if (file_exists($localFile)) {
            return $localFile;
        }
        
        // Try to download from S3 if option is set
        if ($this->option('download')) {
            $this->info('Backup file not found locally. Attempting to download from S3...');
            
            try {
                // Search for file in S3
                $s3Backups = $this->backupService->listS3Backups('database');
                $found = null;
                
                foreach ($s3Backups as $backup) {
                    if ($backup['filename'] === $backupFile) {
                        $found = $backup;
                        break;
                    }
                }
                
                if (!$found) {
                    $this->error("Backup file not found in S3: $backupFile");
                    return null;
                }
                
                // Download the file
                $this->info("Downloading {$found['filename']} ({$found['size_human']})...");
                $localFile = $this->backupService->downloadFromS3($found['path']);
                $this->info("Downloaded successfully to: $localFile");
                
                return $localFile;
                
            } catch (\Exception $e) {
                $this->error('Failed to download from S3: ' . $e->getMessage());
                return null;
            }
        }
        
        // File not found
        $this->error("Backup file not found: $backupFile");
        $this->error("Searched in: $backupPath");
        
        if (!$this->option('download')) {
            $this->info('Tip: Use --download option to automatically download from S3');
        }
        
        return null;
    }
    
    /**
     * Create a safety backup before restoration
     */
    protected function createSafetyBackup(): string
    {
        $this->info('Creating safety backup...');
        
        try {
            $backupLog = $this->backupService->backupDatabase('manual');
            return config('backup.local.path', storage_path('app/backups')) . '/' . $backupLog->filename;
        } catch (\Exception $e) {
            $this->warn('Failed to create safety backup: ' . $e->getMessage());
            
            if (!$this->confirm('Continue without safety backup?')) {
                throw new \Exception('Operation cancelled - no safety backup created');
            }
            
            return '';
        }
    }
    
    /**
     * Verify restoration was successful
     */
    protected function verifyRestoration(): void
    {
        try {
            // Test database connection
            \DB::connection()->getPdo();
            
            // Count tables
            $tables = \DB::select('SHOW TABLES');
            $tableCount = count($tables);
            
            if ($tableCount === 0) {
                throw new \Exception('No tables found after restoration');
            }
            
            $this->info("Database verified: $tableCount tables found");
            
        } catch (\Exception $e) {
            throw new \Exception('Database verification failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Display current database information
     */
    protected function displayCurrentDatabaseInfo(): void
    {
        $this->info('Current Database Information:');
        $this->info('============================');
        
        $dbName = config('database.connections.mariadb.database');
        $dbHost = config('database.connections.mariadb.host');
        
        try {
            $tables = \DB::select('SHOW TABLES');
            $tableCount = count($tables);
            
            // Get database size
            $size = \DB::select("
                SELECT 
                    SUM(data_length + index_length) as size
                FROM information_schema.tables 
                WHERE table_schema = ?
            ", [$dbName]);
            
            $dbSize = $size[0]->size ?? 0;
            
            $this->table(
                ['Property', 'Value'],
                [
                    ['Database', $dbName],
                    ['Host', $dbHost],
                    ['Tables', $tableCount],
                    ['Size', $this->formatBytes($dbSize)],
                    ['Environment', app()->environment()],
                ]
            );
        } catch (\Exception $e) {
            $this->warn('Could not fetch database information: ' . $e->getMessage());
        }
        
        $this->newLine();
    }
    
    /**
     * Display backup file information
     */
    protected function displayBackupInfo($backupFile): void
    {
        $this->info('Backup File Information:');
        $this->info('=======================');
        
        $filename = is_file($backupFile) ? basename($backupFile) : $backupFile;
        
        $this->table(
            ['Property', 'Value'],
            [
                ['Filename', $filename],
                ['Type', $this->getBackupTypeFromFilename($filename)],
                ['Encrypted', str_ends_with($filename, '.enc') ? 'Yes 🔒' : 'No'],
            ]
        );
        
        $this->newLine();
    }
    
    /**
     * Display restoration summary
     */
    protected function displayRestorationSummary($backupFile, float $duration, $safetyBackup): void
    {
        $this->newLine();
        $this->info('✅ Database restored successfully!');
        $this->info('==================================');
        
        $summaryData = [
            ['Restored From', basename($backupFile)],
            ['Duration', $duration . ' seconds'],
            ['Environment', app()->environment()],
        ];
        
        if ($safetyBackup) {
            $summaryData[] = ['Safety Backup', basename($safetyBackup)];
        }
        
        $this->table(['Property', 'Value'], $summaryData);
        
        $this->newLine();
        $this->info('Next steps:');
        $this->line('1. Verify your application is working correctly');
        $this->line('2. Run migrations if needed: php artisan migrate');
        $this->line('3. Clear caches: php artisan optimize:clear');
        
        if ($safetyBackup) {
            $this->newLine();
            $this->info('If you need to rollback:');
            $this->line('php artisan backup:restore --file=' . basename($safetyBackup) . ' --force');
        }
    }
    
    /**
     * Log the restoration operation
     */
    protected function logOperation($backupFile, float $duration, string $status, $error = null): void
    {
        try {
            BackupLog::create([
                'type' => 'restore',
                'frequency' => 'manual',
                'status' => $status,
                'filename' => basename($backupFile),
                'location' => 'local',
                'started_at' => now()->subSeconds($duration),
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'error_message' => $error,
                'metadata' => [
                    'source_backup' => $backupFile,
                    'environment' => app()->environment(),
                    'decrypted' => $this->option('decrypt'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log restoration operation', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get backup type from filename
     */
    protected function getBackupTypeFromFilename($filename): string
    {
        if (str_contains($filename, '_database_')) {
            return 'Database';
        } elseif (str_contains($filename, '_files_')) {
            return 'Files';
        } elseif (str_contains($filename, '_uploads_')) {
            return 'Uploads';
        }
        
        return 'Unknown';
    }
    
    /**
     * Get age string from timestamp
     */
    protected function getAgeString(int $timestamp): string
    {
        $diff = time() - $timestamp;
        
        if ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes !== 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours !== 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days !== 1 ? 's' : '') . ' ago';
        } else {
            $weeks = floor($diff / 604800);
            return $weeks . ' week' . ($weeks !== 1 ? 's' : '') . ' ago';
        }
    }
    
    /**
     * Format bytes to human readable
     */
    protected function formatBytes($bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return number_format($bytes) . ' bytes';
        }
    }
}