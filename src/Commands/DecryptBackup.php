<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use Illuminate\Console\Command;

class DecryptBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:decrypt 
                            {file : Path to the encrypted backup file}
                            {--password= : Decryption password (will prompt if not provided)}
                            {--output= : Output path for decrypted file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decrypt an encrypted backup file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $encryptedFile = $this->argument('file');
        $outputPath = $this->option('output');
        
        // Check if file exists
        if (!file_exists($encryptedFile)) {
            $this->error("File not found: $encryptedFile");
            return 1;
        }
        
        // Check if file is encrypted
        if (!BackupService::isFileEncrypted($encryptedFile)) {
            $this->warn("File does not appear to be encrypted: $encryptedFile");
            if (!$this->confirm('Do you want to continue anyway?')) {
                return 0;
            }
        }
        
        // Get password
        $password = $this->option('password');
        if (!$password) {
            $password = $this->secret('Enter decryption password');
            if (!$password) {
                $this->error('Password is required');
                return 1;
            }
        }
        
        $this->info('Decrypting backup file...');
        $this->info('Input: ' . $encryptedFile);
        
        try {
            $decryptedPath = BackupService::decryptFile($encryptedFile, $password, $outputPath);
            
            $this->info('File decrypted successfully!');
            $this->info('Output: ' . $decryptedPath);
            
            // Show file sizes
            $encryptedSize = filesize($encryptedFile);
            $decryptedSize = filesize($decryptedPath);
            
            $this->table(
                ['Property', 'Value'],
                [
                    ['Encrypted size', $this->formatBytes($encryptedSize)],
                    ['Decrypted size', $this->formatBytes($decryptedSize)],
                    ['Compression ratio', round(($encryptedSize / $decryptedSize) * 100, 2) . '%'],
                ]
            );
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Decryption failed: ' . $e->getMessage());
            $this->error('Please check your password and try again.');
            return 1;
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
            return $bytes . ' bytes';
        }
    }
}
