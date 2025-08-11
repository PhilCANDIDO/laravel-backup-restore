<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DownloadBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:download 
                            {--file= : Specific backup file to download}
                            {--latest : Download the latest backup}
                            {--type=database : Backup type when using --latest (database, files, uploads)}
                            {--decrypt : Decrypt after download if encrypted}
                            {--password= : Decryption password}
                            {--delete-remote : Delete from S3 after successful download}
                            {--output= : Custom output path for downloaded file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download backup files from S3 to local storage';

    protected BackupService $backupService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->backupService = new BackupService();
        
        // Validate S3 configuration
        if (config('backup.driver') !== 's3' && !config('backup.s3.key')) {
            $this->error('S3 is not configured. Please check your backup configuration.');
            return 1;
        }
        
        // Get the file to download
        $fileToDownload = $this->getFileToDownload();
        if (!$fileToDownload) {
            return 1;
        }
        
        try {
            $this->info('Starting download...');
            $this->info('File: ' . $fileToDownload['filename']);
            $this->info('Size: ' . $fileToDownload['size_human']);
            
            // Create progress bar
            $bar = $this->output->createProgressBar(100);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
            
            // Download the file
            $bar->setMessage('Downloading from S3...');
            $bar->start();
            
            $outputPath = $this->option('output');
            $localPath = $this->backupService->downloadFromS3($fileToDownload['path'], $outputPath);
            
            $bar->advance(50);
            
            // Verify download
            $bar->setMessage('Verifying download...');
            if (!file_exists($localPath)) {
                $bar->finish();
                $this->newLine(2);
                $this->error('Download verification failed - file not found locally');
                return 1;
            }
            
            $localSize = filesize($localPath);
            if ($localSize !== $fileToDownload['size']) {
                $bar->finish();
                $this->newLine(2);
                $this->error('Download verification failed - size mismatch');
                $this->error('Expected: ' . $fileToDownload['size'] . ' bytes');
                $this->error('Actual: ' . $localSize . ' bytes');
                unlink($localPath);
                return 1;
            }
            
            $bar->advance(25);
            
            // Decrypt if requested
            $finalPath = $localPath;
            if ($this->option('decrypt') && $fileToDownload['encrypted']) {
                $bar->setMessage('Decrypting file...');
                
                $password = $this->option('password');
                if (!$password) {
                    $password = config('backup.encryption.password');
                }
                
                if (!$password) {
                    $bar->finish();
                    $this->newLine(2);
                    $this->error('Password required for decryption. Use --password option or set BACKUP_ENCRYPTION_PASSWORD in .env');
                    return 1;
                }
                
                try {
                    $decryptedPath = BackupService::decryptFile($localPath, $password);
                    $finalPath = $decryptedPath;
                    
                    // Remove encrypted file
                    unlink($localPath);
                } catch (\Exception $e) {
                    $bar->finish();
                    $this->newLine(2);
                    $this->error('Decryption failed: ' . $e->getMessage());
                    return 1;
                }
            }
            
            $bar->advance(20);
            
            // Delete from S3 if requested
            if ($this->option('delete-remote')) {
                $bar->setMessage('Deleting from S3...');
                
                try {
                    $this->deleteFromS3($fileToDownload['path']);
                    $this->info(' (deleted from S3)');
                } catch (\Exception $e) {
                    $this->warn(' (failed to delete from S3: ' . $e->getMessage() . ')');
                }
            }
            
            $bar->advance(5);
            $bar->finish();
            
            $this->newLine(2);
            
            // Display summary
            $this->displaySummary($fileToDownload, $finalPath);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Download failed: ' . $e->getMessage());
            Log::error('Backup download failed', [
                'file' => $fileToDownload['filename'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }
    
    /**
     * Get the file to download
     */
    protected function getFileToDownload(): ?array
    {
        // Specific file requested
        if ($file = $this->option('file')) {
            return $this->findFileInS3($file);
        }
        
        // Latest backup requested
        if ($this->option('latest')) {
            return $this->getLatestBackup();
        }
        
        // Interactive selection
        return $this->selectFileInteractively();
    }
    
    /**
     * Find a specific file in S3
     */
    protected function findFileInS3($filename): ?array
    {
        $this->info('Searching for file in S3...');
        
        try {
            $s3Backups = $this->backupService->listS3Backups();
            
            foreach ($s3Backups as $backup) {
                if ($backup['filename'] === $filename || $backup['path'] === $filename) {
                    return $backup;
                }
            }
            
            $this->error("File not found in S3: $filename");
            $this->info('Tip: Use "php artisan backup:list --s3" to see available files');
            
            return null;
            
        } catch (\Exception $e) {
            $this->error('Failed to search S3: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get the latest backup of specified type
     */
    protected function getLatestBackup(): ?array
    {
        $type = $this->option('type');
        
        $this->info("Fetching latest $type backup from S3...");
        
        try {
            $s3Backups = $this->backupService->listS3Backups($type);
            
            if (empty($s3Backups)) {
                $this->error("No $type backups found in S3");
                return null;
            }
            
            // Sort by date descending and get the first one
            usort($s3Backups, function($a, $b) {
                return $b['created_at'] - $a['created_at'];
            });
            
            return $s3Backups[0];
            
        } catch (\Exception $e) {
            $this->error('Failed to fetch S3 backups: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Select file interactively
     */
    protected function selectFileInteractively(): ?array
    {
        $this->info('Fetching S3 backups...');
        
        try {
            $s3Backups = $this->backupService->listS3Backups();
            
            if (empty($s3Backups)) {
                $this->error('No backups found in S3');
                return null;
            }
            
            // Sort by date descending
            usort($s3Backups, function($a, $b) {
                return $b['created_at'] - $a['created_at'];
            });
            
            // Limit to 30 most recent
            $s3Backups = array_slice($s3Backups, 0, 30);
            
            // Prepare choices
            $choices = [];
            foreach ($s3Backups as $index => $backup) {
                $age = $this->getAgeString($backup['created_at']);
                $label = sprintf(
                    "%s (%s, %s, %s%s)",
                    $backup['filename'],
                    ucfirst($backup['type']),
                    $backup['size_human'],
                    $age,
                    $backup['encrypted'] ? ', 🔒' : ''
                );
                $choices[] = $label;
            }
            
            // Ask user to select
            $selected = $this->choice('Select a backup to download', $choices, 0);
            $selectedIndex = array_search($selected, $choices);
            
            return $s3Backups[$selectedIndex];
            
        } catch (\Exception $e) {
            $this->error('Failed to fetch S3 backups: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete file from S3
     */
    protected function deleteFromS3($s3Path): void
    {
        $s3Config = config('backup.s3');
        
        // Configure S3 client
        $disk = \Storage::build([
            'driver' => 's3',
            'key' => $s3Config['key'],
            'secret' => $s3Config['secret'],
            'region' => $s3Config['region'],
            'bucket' => $s3Config['bucket'],
            'endpoint' => $s3Config['endpoint'],
            'use_path_style_endpoint' => true,
        ]);
        
        // Parse S3 path
        if (str_starts_with($s3Path, 's3://')) {
            $s3Path = str_replace('s3://' . $s3Config['bucket'] . '/', '', $s3Path);
        }
        
        $disk->delete($s3Path);
    }
    
    /**
     * Display download summary
     */
    protected function displaySummary($fileInfo, $localPath): void
    {
        $this->info('✅ Download completed successfully!');
        $this->info('==================================');
        
        $summaryData = [
            ['Filename', $fileInfo['filename']],
            ['Type', ucfirst($fileInfo['type'])],
            ['Size', $fileInfo['size_human']],
            ['Downloaded To', $localPath],
        ];
        
        if ($this->option('decrypt') && $fileInfo['encrypted']) {
            $summaryData[] = ['Decrypted', 'Yes'];
        }
        
        if ($this->option('delete-remote')) {
            $summaryData[] = ['Deleted from S3', 'Yes'];
        }
        
        $this->table(['Property', 'Value'], $summaryData);
        
        // Show next steps based on file type
        $this->newLine();
        $this->info('Next steps:');
        
        if ($fileInfo['type'] === 'database') {
            $this->line('• Restore this backup: php artisan backup:restore --file=' . basename($localPath));
            $this->line('• Create staging environment: php artisan backup:create-staging --from=' . basename($localPath));
        } elseif ($fileInfo['type'] === 'files' || $fileInfo['type'] === 'uploads') {
            $this->line('• Extract archive: tar -xzf ' . $localPath);
        }
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
}