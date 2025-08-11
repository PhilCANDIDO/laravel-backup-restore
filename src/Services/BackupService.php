<?php

namespace MyDatabase\BackupRestore\Services;

use MyDatabase\BackupRestore\Models\BackupLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Carbon\Carbon;

class BackupService
{
    protected $backupPath;
    protected $s3Enabled = false;
    protected $kopiaEnabled = false;
    protected $encryptionEnabled = false;
    protected $encryptionPassword;
    protected $encryptionCipher;

    public function __construct()
    {
        $this->backupPath = config('backup.local.path', storage_path('app/backups'));
        $this->s3Enabled = !empty(config('backup.s3.key')) && config('backup.driver') === 's3';
        $this->kopiaEnabled = config('backup.kopia.enabled', false);
        $this->encryptionEnabled = config('backup.encryption.enabled', false);
        $this->encryptionPassword = config('backup.encryption.password');
        $this->encryptionCipher = config('backup.encryption.cipher', 'aes-256-cbc');
        
        // Create backup directory if it doesn't exist
        if (!file_exists($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
        
        // Validate encryption configuration
        if ($this->encryptionEnabled && empty($this->encryptionPassword)) {
            throw new \Exception('Backup encryption is enabled but no password is set');
        }
    }

    /**
     * Backup the database
     */
    public function backupDatabase($frequency = 'manual'): BackupLog
    {
        $startTime = now();
        $backupLog = BackupLog::create([
            'type' => 'database',
            'frequency' => $frequency,
            'status' => 'started',
            'user_id' => Auth::id(),
            'started_at' => $startTime,
        ]);

        try {
            // Generate filename
            $filename = $this->generateBackupFilename('database', 'sql');
            $filepath = $this->backupPath . '/' . $filename;

            // Get database credentials
            $dbHost = config('database.connections.mariadb.host');
            $dbPort = config('database.connections.mariadb.port');
            $dbName = config('database.connections.mariadb.database');
            $dbUser = config('database.connections.mariadb.username');
            $dbPass = config('database.connections.mariadb.password');

            // Build mysqldump command
            $command = [
                'mysqldump',
                '--host=' . $dbHost,
                '--port=' . $dbPort,
                '--user=' . $dbUser,
                '--password=' . $dbPass,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--events',
                $dbName,
            ];

            // Execute mysqldump
            $process = new Process($command);
            $process->setTimeout(300); // 5 minutes timeout
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Save output to file
            file_put_contents($filepath, $process->getOutput());

            // Compress the backup
            $compressedPath = $this->compressFile($filepath);
            
            // Encrypt if enabled
            if ($this->encryptionEnabled) {
                $encryptedPath = $this->encryptFile($compressedPath);
                // Delete unencrypted file
                unlink($compressedPath);
                $compressedPath = $encryptedPath;
            }
            
            // Get file size
            $fileSize = filesize($compressedPath);

            // Upload to S3 if enabled
            $location = 'local';
            if ($this->s3Enabled) {
                $location = $this->uploadToS3($compressedPath, 'database/' . $frequency);
            }

            // Upload to Kopia if enabled
            if ($this->kopiaEnabled) {
                $this->uploadToKopia($compressedPath, 'database');
            }

            // Clean up old backups according to retention policy
            $this->cleanOldBackups('database', $frequency);

            // Update backup log
            $backupLog->update([
                'status' => 'success',
                'filename' => basename($compressedPath),
                'location' => $location,
                'size_bytes' => $fileSize,
                'duration_seconds' => $startTime->diffInSeconds(now()),
                'completed_at' => now(),
                'metadata' => [
                    'tables_count' => $this->getTablesCount(),
                    'rows_count' => $this->getTotalRowsCount(),
                    'encrypted' => $this->encryptionEnabled,
                    'cipher' => $this->encryptionEnabled ? $this->encryptionCipher : null,
                ],
            ]);

            // Log success
            Log::info('Database backup completed successfully', [
                'filename' => $filename,
                'size' => $fileSize,
                'duration' => $startTime->diffInSeconds(now()),
            ]);

        } catch (\Exception $e) {
            // Update backup log with error
            $backupLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => $startTime->diffInSeconds(now()),
            ]);

            // Log error
            Log::error('Database backup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        return $backupLog;
    }

    /**
     * Backup application files
     */
    public function backupFiles($frequency = 'manual'): BackupLog
    {
        $startTime = now();
        $backupLog = BackupLog::create([
            'type' => 'files',
            'frequency' => $frequency,
            'status' => 'started',
            'user_id' => Auth::id(),
            'started_at' => $startTime,
        ]);

        try {
            // Generate filename
            $filename = $this->generateBackupFilename('files', 'tar');
            $filepath = $this->backupPath . '/' . $filename;

            // Directories to backup
            $directories = [
                'app',
                'config',
                'database',
                'resources',
                'routes',
            ];

            // Build tar command
            $command = ['tar', '-czf', $filepath];
            foreach ($directories as $dir) {
                $command[] = base_path($dir);
            }

            // Execute tar
            $process = new Process($command);
            $process->setTimeout(600); // 10 minutes timeout
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Encrypt if enabled
            if ($this->encryptionEnabled) {
                $encryptedPath = $this->encryptFile($filepath);
                // Delete unencrypted file
                unlink($filepath);
                $filepath = $encryptedPath;
            }

            // Get file size
            $fileSize = filesize($filepath);

            // Upload to S3 if enabled
            $location = 'local';
            if ($this->s3Enabled) {
                $location = $this->uploadToS3($filepath, 'application/' . $frequency);
            }

            // Upload to Kopia if enabled
            if ($this->kopiaEnabled) {
                $this->uploadToKopia($filepath, 'application');
            }

            // Clean up old backups
            $this->cleanOldBackups('files', $frequency);

            // Update backup log
            $backupLog->update([
                'status' => 'success',
                'filename' => $filename,
                'location' => $location,
                'size_bytes' => $fileSize,
                'duration_seconds' => $startTime->diffInSeconds(now()),
                'completed_at' => now(),
                'metadata' => [
                    'directories_backed_up' => $directories,
                ],
            ]);

            Log::info('Application files backup completed successfully', [
                'filename' => $filename,
                'size' => $fileSize,
            ]);

        } catch (\Exception $e) {
            $backupLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => $startTime->diffInSeconds(now()),
            ]);

            Log::error('Application files backup failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $backupLog;
    }

    /**
     * Backup user uploads
     */
    public function backupUploads($frequency = 'manual'): BackupLog
    {
        $startTime = now();
        $backupLog = BackupLog::create([
            'type' => 'uploads',
            'frequency' => $frequency,
            'status' => 'started',
            'user_id' => Auth::id(),
            'started_at' => $startTime,
        ]);

        try {
            $uploadsPath = storage_path('app/public');
            
            // Check if uploads directory exists
            if (!is_dir($uploadsPath)) {
                throw new \Exception('Uploads directory does not exist');
            }

            // Generate filename
            $filename = $this->generateBackupFilename('uploads', 'tar');
            $filepath = $this->backupPath . '/' . $filename;

            // Create tar archive of uploads
            $command = ['tar', '-czf', $filepath, $uploadsPath];
            $process = new Process($command);
            $process->setTimeout(1200); // 20 minutes timeout for large uploads
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Encrypt if enabled
            if ($this->encryptionEnabled) {
                $encryptedPath = $this->encryptFile($filepath);
                // Delete unencrypted file
                unlink($filepath);
                $filepath = $encryptedPath;
            }

            $fileSize = filesize($filepath);

            // Upload to S3 if enabled
            $location = 'local';
            if ($this->s3Enabled) {
                $location = $this->uploadToS3($filepath, 'uploads/' . $frequency);
            }

            // Upload to Kopia if enabled
            if ($this->kopiaEnabled) {
                $this->uploadToKopia($filepath, 'uploads');
            }

            // Clean up old backups
            $this->cleanOldBackups('uploads', $frequency);

            $backupLog->update([
                'status' => 'success',
                'filename' => $filename,
                'location' => $location,
                'size_bytes' => $fileSize,
                'duration_seconds' => $startTime->diffInSeconds(now()),
                'completed_at' => now(),
            ]);

            Log::info('Uploads backup completed successfully', [
                'filename' => $filename,
                'size' => $fileSize,
            ]);

        } catch (\Exception $e) {
            $backupLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => $startTime->diffInSeconds(now()),
            ]);

            Log::error('Uploads backup failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $backupLog;
    }

    /**
     * Compress a file using gzip
     */
    protected function compressFile($filepath): string
    {
        $compressedPath = $filepath . '.gz';
        
        $command = ['gzip', '-9', '-c', $filepath];
        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        file_put_contents($compressedPath, $process->getOutput());
        
        // Remove uncompressed file
        unlink($filepath);

        return $compressedPath;
    }

    /**
     * Upload backup to S3
     */
    protected function uploadToS3($filepath, $folder): string
    {
        $s3Config = config('backup.s3');
        
        // Configure S3 client
        $disk = Storage::build([
            'driver' => 's3',
            'key' => $s3Config['key'],
            'secret' => $s3Config['secret'],
            'region' => $s3Config['region'],
            'bucket' => $s3Config['bucket'],
            'endpoint' => $s3Config['endpoint'],
            'use_path_style_endpoint' => true,
        ]);

        $s3Path = $folder . '/' . basename($filepath);
        
        // Upload file
        $disk->put($s3Path, file_get_contents($filepath));

        return 's3://' . $s3Config['bucket'] . '/' . $s3Path;
    }

    /**
     * Upload backup to Kopia repository
     */
    protected function uploadToKopia($filepath, $type): void
    {
        if (!$this->kopiaEnabled) {
            return;
        }

        $kopiaRepo = config('backup.kopia.repository');
        $kopiaPassword = config('backup.kopia.password');

        // Kopia snapshot command
        $command = [
            'kopia',
            'snapshot',
            'create',
            $filepath,
            '--description=' . $type . ' backup',
            '--tags=type:' . $type,
        ];

        $process = new Process($command);
        $process->setEnv(['KOPIA_PASSWORD' => $kopiaPassword]);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::warning('Kopia backup failed', [
                'error' => $process->getErrorOutput(),
            ]);
        }
    }

    /**
     * Clean old backups according to retention policy
     */
    protected function cleanOldBackups($type, $frequency): void
    {
        $retentionDays = config('backup.retention.' . $frequency, 7);
        $cutoffDate = Carbon::now()->subDays($retentionDays);

        // Get old backups to clean
        $oldBackups = BackupLog::where('type', $type)
            ->where('frequency', $frequency)
            ->where('started_at', '<', $cutoffDate)
            ->successful()
            ->get();

        foreach ($oldBackups as $backup) {
            // Delete local file if exists
            $localPath = $this->backupPath . '/' . $backup->filename;
            if (file_exists($localPath)) {
                unlink($localPath);
                Log::debug('Deleted local backup file', ['path' => $localPath]);
            }

            // Delete from S3 if enabled and file exists there
            if ($this->s3Enabled && str_starts_with($backup->location, 's3://')) {
                try {
                    $this->deleteFromS3($backup->location);
                    Log::debug('Deleted S3 backup file', ['location' => $backup->location]);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete S3 backup', [
                        'location' => $backup->location,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Mark as cleaned in database
            $backup->update([
                'metadata' => array_merge($backup->metadata ?? [], [
                    'cleaned_at' => now()->toDateTimeString(),
                    'cleaned_from' => $this->s3Enabled ? 's3_and_local' : 'local',
                ]),
            ]);
        }

        Log::info('Cleaned old backups according to retention policy', [
            'type' => $type,
            'frequency' => $frequency,
            'retention_days' => $retentionDays,
            'cleaned_count' => $oldBackups->count(),
            'cutoff_date' => $cutoffDate->toDateTimeString(),
        ]);
    }

    /**
     * Delete backup from S3
     */
    protected function deleteFromS3($s3Location): void
    {
        // Parse S3 location (format: s3://bucket/path/to/file)
        if (!preg_match('/^s3:\/\/([^\/]+)\/(.+)$/', $s3Location, $matches)) {
            throw new \Exception('Invalid S3 location format: ' . $s3Location);
        }

        $bucket = $matches[1];
        $path = $matches[2];

        $s3Config = config('backup.s3');
        
        // Configure S3 client
        $disk = Storage::build([
            'driver' => 's3',
            'key' => $s3Config['key'],
            'secret' => $s3Config['secret'],
            'region' => $s3Config['region'],
            'bucket' => $bucket,
            'endpoint' => $s3Config['endpoint'],
            'use_path_style_endpoint' => true,
        ]);

        // Delete the file
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * Generate backup filename
     */
    protected function generateBackupFilename($type, $extension): string
    {
        return sprintf(
            '%s_backup_%s_%s.%s',
            config('app.name', 'mydatabase'),
            $type,
            now()->format('Y-m-d_His'),
            $extension
        );
    }

    /**
     * Get total number of tables in database
     */
    protected function getTablesCount(): int
    {
        $tables = DB::select('SHOW TABLES');
        return count($tables);
    }

    /**
     * Get total number of rows across all tables
     */
    protected function getTotalRowsCount(): int
    {
        $totalRows = 0;
        $tables = DB::select('SHOW TABLES');
        $dbName = config('database.connections.mariadb.database');

        foreach ($tables as $table) {
            $tableName = $table->{'Tables_in_' . $dbName};
            $count = DB::table($tableName)->count();
            $totalRows += $count;
        }

        return $totalRows;
    }

    /**
     * Get latest successful backup for a type
     */
    public static function getLatestBackup($type): ?BackupLog
    {
        return BackupLog::where('type', $type)
            ->successful()
            ->orderBy('started_at', 'desc')
            ->first();
    }

    /**
     * Get backup statistics
     */
    public static function getBackupStats(): array
    {
        $stats = [];
        $types = ['database', 'files', 'uploads'];

        foreach ($types as $type) {
            $latest = self::getLatestBackup($type);
            $stats[$type] = [
                'last_backup' => $latest ? $latest->started_at : null,
                'last_status' => $latest ? $latest->status : 'never',
                'last_size' => $latest ? $latest->formatted_size : null,
                'total_backups' => BackupLog::where('type', $type)->count(),
                'successful_backups' => BackupLog::where('type', $type)->successful()->count(),
                'failed_backups' => BackupLog::where('type', $type)->failed()->count(),
            ];
        }

        return $stats;
    }

    /**
     * Encrypt a file using OpenSSL
     */
    protected function encryptFile($filepath): string
    {
        $encryptedPath = $filepath . '.enc';
        
        // Use OpenSSL command line for better performance with large files
        $command = [
            'openssl',
            'enc',
            '-' . $this->encryptionCipher,
            '-salt',
            '-in', $filepath,
            '-out', $encryptedPath,
            '-pass', 'pass:' . $this->encryptionPassword,
            '-pbkdf2',  // Use PBKDF2 for key derivation (more secure)
            '-iter', '10000'  // Number of iterations for key derivation
        ];
        
        $process = new Process($command);
        $process->setTimeout(600); // 10 minutes timeout
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \Exception('Failed to encrypt backup file: ' . $process->getErrorOutput());
        }
        
        Log::info('Backup file encrypted successfully', [
            'original' => basename($filepath),
            'encrypted' => basename($encryptedPath),
            'cipher' => $this->encryptionCipher,
        ]);
        
        return $encryptedPath;
    }

    /**
     * Decrypt a file using OpenSSL
     * This method is for restoration purposes
     */
    public static function decryptFile($encryptedPath, $password, $outputPath = null): string
    {
        if (!file_exists($encryptedPath)) {
            throw new \Exception('Encrypted file not found: ' . $encryptedPath);
        }
        
        // If no output path specified, remove .enc extension
        if (!$outputPath) {
            $outputPath = preg_replace('/\.enc$/', '', $encryptedPath);
            if ($outputPath === $encryptedPath) {
                $outputPath = $encryptedPath . '.decrypted';
            }
        }
        
        $cipher = config('backup.encryption.cipher', 'aes-256-cbc');
        
        // Use OpenSSL command line for decryption
        $command = [
            'openssl',
            'enc',
            '-d',  // Decrypt mode
            '-' . $cipher,
            '-in', $encryptedPath,
            '-out', $outputPath,
            '-pass', 'pass:' . $password,
            '-pbkdf2',  // Use PBKDF2 for key derivation
            '-iter', '10000'  // Same number of iterations as encryption
        ];
        
        $process = new Process($command);
        $process->setTimeout(600); // 10 minutes timeout
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \Exception('Failed to decrypt backup file: ' . $process->getErrorOutput());
        }
        
        Log::info('Backup file decrypted successfully', [
            'encrypted' => basename($encryptedPath),
            'decrypted' => basename($outputPath),
        ]);
        
        return $outputPath;
    }

    /**
     * Check if a file is encrypted
     */
    public static function isFileEncrypted($filepath): bool
    {
        // Check by file extension
        if (str_ends_with($filepath, '.enc')) {
            return true;
        }
        
        // Check file header for OpenSSL encryption signature
        if (file_exists($filepath)) {
            $handle = fopen($filepath, 'rb');
            $header = fread($handle, 16);
            fclose($handle);
            
            // OpenSSL encrypted files typically start with "Salted__"
            if (str_starts_with($header, 'Salted__')) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate a strong encryption password
     */
    public static function generateEncryptionPassword(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }

    /**
     * List local backup files
     */
    public function listLocalBackups($type = null): array
    {
        $backups = [];
        $backupPath = config('backup.local.path', storage_path('app/backups'));
        
        if (!is_dir($backupPath)) {
            return $backups;
        }
        
        $files = scandir($backupPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $filepath = $backupPath . '/' . $file;
            if (!is_file($filepath)) {
                continue;
            }
            
            // Parse backup type from filename
            $backupType = $this->getBackupTypeFromFilename($file);
            if ($type && $backupType !== $type) {
                continue;
            }
            
            $backups[] = [
                'filename' => $file,
                'path' => $filepath,
                'size' => filesize($filepath),
                'size_human' => $this->formatBytes(filesize($filepath)),
                'created_at' => filemtime($filepath),
                'created_at_human' => date('Y-m-d H:i:s', filemtime($filepath)),
                'type' => $backupType,
                'encrypted' => self::isFileEncrypted($filepath),
                'location' => 'local',
            ];
        }
        
        // Sort by created_at descending
        usort($backups, function($a, $b) {
            return $b['created_at'] - $a['created_at'];
        });
        
        return $backups;
    }

    /**
     * List S3 backup files
     */
    public function listS3Backups($type = null): array
    {
        if (!$this->s3Enabled) {
            throw new \Exception('S3 is not configured');
        }
        
        $backups = [];
        $s3Config = config('backup.s3');
        
        // Configure S3 client
        $disk = Storage::build([
            'driver' => 's3',
            'key' => $s3Config['key'],
            'secret' => $s3Config['secret'],
            'region' => $s3Config['region'],
            'bucket' => $s3Config['bucket'],
            'endpoint' => $s3Config['endpoint'],
            'use_path_style_endpoint' => true,
        ]);
        
        // List all files in the bucket (including subdirectories)
        try {
            // Get all files in the bucket
            $allFiles = $disk->allFiles();
            
            foreach ($allFiles as $file) {
                // Skip non-backup files
                if (!str_contains($file, '_backup_')) {
                    continue;
                }
                
                $filename = basename($file);
                
                // Parse backup type from filename
                $backupType = $this->getBackupTypeFromFilename($filename);
                if ($type && $backupType !== $type) {
                    continue;
                }
                
                // Get file metadata
                try {
                    $size = $disk->size($file);
                    $lastModified = $disk->lastModified($file);
                } catch (\Exception $e) {
                    // If we can't get metadata, use defaults
                    $size = 0;
                    $lastModified = time();
                }
                
                $backups[] = [
                    'filename' => $filename,
                    'path' => $file,
                    's3_path' => 's3://' . $s3Config['bucket'] . '/' . $file,
                    'size' => $size,
                    'size_human' => $this->formatBytes($size),
                    'created_at' => $lastModified,
                    'created_at_human' => date('Y-m-d H:i:s', $lastModified),
                    'type' => $backupType,
                    'encrypted' => str_ends_with($filename, '.enc'),
                    'location' => 's3',
                ];
            }
            
            // If allFiles doesn't work, try listing by directories
            if (empty($backups)) {
                $directories = ['database', 'application', 'uploads'];
                
                foreach ($directories as $dir) {
                    try {
                        // Try both files() and allFiles() for each directory
                        $dirFiles = $disk->files($dir);
                        
                        // Also check subdirectories like database/daily, database/manual
                        $subdirs = ['daily', 'manual', 'weekly', 'monthly', 'safety'];
                        foreach ($subdirs as $subdir) {
                            $subdirPath = $dir . '/' . $subdir;
                            try {
                                $subdirFiles = $disk->files($subdirPath);
                                $dirFiles = array_merge($dirFiles, $subdirFiles);
                            } catch (\Exception $e) {
                                // Subdirectory might not exist, continue
                            }
                        }
                        
                        foreach ($dirFiles as $file) {
                            $filename = basename($file);
                            
                            // Parse backup type from filename
                            $backupType = $this->getBackupTypeFromFilename($filename);
                            if ($type && $backupType !== $type) {
                                continue;
                            }
                            
                            // Get file metadata
                            try {
                                $size = $disk->size($file);
                                $lastModified = $disk->lastModified($file);
                            } catch (\Exception $e) {
                                $size = 0;
                                $lastModified = time();
                            }
                            
                            $backups[] = [
                                'filename' => $filename,
                                'path' => $file,
                                's3_path' => 's3://' . $s3Config['bucket'] . '/' . $file,
                                'size' => $size,
                                'size_human' => $this->formatBytes($size),
                                'created_at' => $lastModified,
                                'created_at_human' => date('Y-m-d H:i:s', $lastModified),
                                'type' => $backupType,
                                'encrypted' => str_ends_with($filename, '.enc'),
                                'location' => 's3',
                            ];
                        }
                    } catch (\Exception $e) {
                        // Directory might not exist, continue
                        Log::debug('Failed to list S3 directory', ['dir' => $dir, 'error' => $e->getMessage()]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to list S3 backups', ['error' => $e->getMessage()]);
            throw new \Exception('Failed to list S3 backups: ' . $e->getMessage());
        }
        
        // Remove duplicates if any
        $uniqueBackups = [];
        $seen = [];
        foreach ($backups as $backup) {
            if (!in_array($backup['filename'], $seen)) {
                $uniqueBackups[] = $backup;
                $seen[] = $backup['filename'];
            }
        }
        $backups = $uniqueBackups;
        
        // Sort by created_at descending
        usort($backups, function($a, $b) {
            return $b['created_at'] - $a['created_at'];
        });
        
        return $backups;
    }

    /**
     * Download backup from S3
     */
    public function downloadFromS3($s3Path, $localPath = null): string
    {
        if (!$this->s3Enabled) {
            throw new \Exception('S3 is not configured');
        }
        
        $s3Config = config('backup.s3');
        
        // Configure S3 client
        $disk = Storage::build([
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
        
        if (!$disk->exists($s3Path)) {
            throw new \Exception("File not found in S3: $s3Path");
        }
        
        // Determine local path
        if (!$localPath) {
            $localPath = $this->backupPath . '/' . basename($s3Path);
        }
        
        // Get file size using the correct method
        try {
            $fileSize = $disk->size($s3Path);
        } catch (\Exception $e) {
            // If we can't get size, continue anyway
            $fileSize = 0;
        }
        
        // Check available disk space (only if we have the file size)
        if ($fileSize > 0) {
            $availableSpace = disk_free_space(dirname($localPath));
            
            if ($availableSpace < $fileSize * 1.1) { // 10% buffer
                throw new \Exception('Insufficient disk space for download');
            }
        }
        
        // Download file
        $content = $disk->get($s3Path);
        file_put_contents($localPath, $content);
        
        // Verify download
        if (!file_exists($localPath)) {
            throw new \Exception('Download failed - file not created');
        }
        
        // If we knew the size, verify it matches
        if ($fileSize > 0 && filesize($localPath) !== $fileSize) {
            unlink($localPath);
            throw new \Exception('Download verification failed - size mismatch');
        }
        
        return $localPath;
    }

    /**
     * Restore database from backup file
     */
    public function restoreDatabase($backupFile, $decrypt = false, $password = null): void
    {
        if (!file_exists($backupFile)) {
            throw new \Exception("Backup file not found: $backupFile");
        }
        
        // Decrypt if needed
        if ($decrypt || self::isFileEncrypted($backupFile)) {
            if (!$password) {
                $password = config('backup.encryption.password');
            }
            if (!$password) {
                throw new \Exception('Password required for encrypted backup');
            }
            
            $decryptedFile = self::decryptFile($backupFile, $password);
            $backupFile = $decryptedFile;
        }
        
        // Decompress if needed
        if (str_ends_with($backupFile, '.gz')) {
            $decompressedFile = str_replace('.gz', '', $backupFile);
            
            $process = new Process(['gunzip', '-c', $backupFile]);
            $process->setTimeout(600);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
            
            file_put_contents($decompressedFile, $process->getOutput());
            $backupFile = $decompressedFile;
        }
        
        // Get database credentials
        $dbHost = config('database.connections.mariadb.host');
        $dbPort = config('database.connections.mariadb.port');
        $dbName = config('database.connections.mariadb.database');
        $dbUser = config('database.connections.mariadb.username');
        $dbPass = config('database.connections.mariadb.password');
        
        // Restore database
        $command = [
            'mysql',
            '--host=' . $dbHost,
            '--port=' . $dbPort,
            '--user=' . $dbUser,
            '--password=' . $dbPass,
            $dbName,
        ];
        
        $process = new Process($command);
        $process->setInput(file_get_contents($backupFile));
        $process->setTimeout(1200); // 20 minutes timeout
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        
        // Clean up temporary files
        if (isset($decryptedFile) && file_exists($decryptedFile)) {
            unlink($decryptedFile);
        }
        if (isset($decompressedFile) && file_exists($decompressedFile)) {
            unlink($decompressedFile);
        }
    }

    /**
     * Restore database to different database/host
     */
    public function restoreToDifferentDatabase($backupFile, array $targetConfig, $decrypt = false, $password = null): void
    {
        if (!file_exists($backupFile)) {
            throw new \Exception("Backup file not found: $backupFile");
        }
        
        // Validate target configuration
        $required = ['host', 'database', 'username', 'password'];
        foreach ($required as $key) {
            if (!isset($targetConfig[$key])) {
                throw new \Exception("Missing required target configuration: $key");
            }
        }
        
        $targetHost = $targetConfig['host'];
        $targetPort = $targetConfig['port'] ?? 3306;
        $targetDatabase = $targetConfig['database'];
        $targetUsername = $targetConfig['username'];
        $targetPassword = $targetConfig['password'];
        
        // Test target connection
        $testCommand = [
            'mysql',
            '--host=' . $targetHost,
            '--port=' . $targetPort,
            '--user=' . $targetUsername,
            '--password=' . $targetPassword,
            '-e', 'SELECT 1',
        ];
        
        $process = new Process($testCommand);
        $process->setTimeout(30);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \Exception('Cannot connect to target database: ' . $process->getErrorOutput());
        }
        
        // Create database if requested
        if ($targetConfig['create_database'] ?? false) {
            $createCommand = [
                'mysql',
                '--host=' . $targetHost,
                '--port=' . $targetPort,
                '--user=' . $targetUsername,
                '--password=' . $targetPassword,
                '-e', "CREATE DATABASE IF NOT EXISTS `$targetDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            
            $process = new Process($createCommand);
            $process->setTimeout(30);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new \Exception('Failed to create target database: ' . $process->getErrorOutput());
            }
        }
        
        // Decrypt if needed
        if ($decrypt || self::isFileEncrypted($backupFile)) {
            if (!$password) {
                $password = config('backup.encryption.password');
            }
            if (!$password) {
                throw new \Exception('Password required for encrypted backup');
            }
            
            $decryptedFile = self::decryptFile($backupFile, $password);
            $backupFile = $decryptedFile;
        }
        
        // Decompress if needed
        if (str_ends_with($backupFile, '.gz')) {
            $decompressedFile = str_replace('.gz', '', $backupFile);
            
            $process = new Process(['gunzip', '-c', $backupFile]);
            $process->setTimeout(600);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
            
            file_put_contents($decompressedFile, $process->getOutput());
            $backupFile = $decompressedFile;
        }
        
        // Restore to target database
        $restoreCommand = [
            'mysql',
            '--host=' . $targetHost,
            '--port=' . $targetPort,
            '--user=' . $targetUsername,
            '--password=' . $targetPassword,
            $targetDatabase,
        ];
        
        $process = new Process($restoreCommand);
        $process->setInput(file_get_contents($backupFile));
        $process->setTimeout(1200); // 20 minutes timeout
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        
        // Clean up temporary files
        if (isset($decryptedFile) && file_exists($decryptedFile)) {
            unlink($decryptedFile);
        }
        if (isset($decompressedFile) && file_exists($decompressedFile)) {
            unlink($decompressedFile);
        }
    }

    /**
     * Get backup type from filename
     */
    protected function getBackupTypeFromFilename($filename): string
    {
        if (str_contains($filename, '_database_')) {
            return 'database';
        } elseif (str_contains($filename, '_files_')) {
            return 'files';
        } elseif (str_contains($filename, '_uploads_')) {
            return 'uploads';
        }
        
        return 'unknown';
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