<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use MyDatabase\BackupRestore\Models\BackupLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CreateStagingEnvironment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:create-staging 
                            {--from= : Backup file to restore from (required)}
                            {--env-name= : Environment name for identification}
                            {--target-host= : Target database host}
                            {--target-database= : Target database name}
                            {--target-username= : Target database username}
                            {--target-password= : Target database password}
                            {--target-port=3306 : Target database port}
                            {--target-config= : JSON config file with target database settings}
                            {--create-database : Create database if it doesn\'t exist}
                            {--anonymize : Anonymize sensitive data after restore}
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
    protected $description = 'Create a staging environment by restoring a backup to a different database';

    protected BackupService $backupService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->backupService = new BackupService();
        
        // Get backup file
        $backupFile = $this->option('from');
        if (!$backupFile) {
            $this->error('The --from option is required. Please specify a backup file.');
            return 1;
        }
        
        // Get target configuration
        $targetConfig = $this->getTargetConfig();
        if (!$targetConfig) {
            return 1;
        }
        
        // Display configuration
        $this->displayConfiguration($backupFile, $targetConfig);
        
        // Dry run mode
        if ($this->option('dry-run')) {
            $this->info('DRY RUN: No changes will be made.');
            $this->info('Configuration validated successfully.');
            return 0;
        }
        
        // Confirmation
        if (!$this->option('force')) {
            if (!$this->confirm('Do you want to proceed with creating the staging environment?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }
        
        try {
            // Check if backup file exists locally
            $localBackupFile = $this->resolveBackupFile($backupFile);
            if (!$localBackupFile) {
                return 1;
            }
            
            $this->info('Starting staging environment creation...');
            $startTime = microtime(true);
            
            // Add create_database flag to config
            if ($this->option('create-database')) {
                $targetConfig['create_database'] = true;
            }
            
            // Restore to target database
            $this->info('Restoring backup to target database...');
            $this->backupService->restoreToDifferentDatabase(
                $localBackupFile,
                $targetConfig,
                $this->option('decrypt'),
                $this->option('password')
            );
            
            $this->info('Database restored successfully!');
            
            // Anonymize data if requested
            if ($this->option('anonymize')) {
                $this->anonymizeData($targetConfig);
            }
            
            // Log the operation
            $duration = round(microtime(true) - $startTime, 2);
            $this->logOperation($backupFile, $targetConfig, $duration);
            
            // Display summary
            $this->displaySummary($targetConfig, $duration);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Failed to create staging environment: ' . $e->getMessage());
            Log::error('Staging environment creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }
    
    /**
     * Get target database configuration
     */
    protected function getTargetConfig(): ?array
    {
        // Check if config file is provided
        if ($configFile = $this->option('target-config')) {
            if (!file_exists($configFile)) {
                $this->error("Config file not found: $configFile");
                return null;
            }
            
            $config = json_decode(file_get_contents($configFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON in config file: ' . json_last_error_msg());
                return null;
            }
            
            return $config;
        }
        
        // Get config from command line options
        $host = $this->option('target-host');
        $database = $this->option('target-database');
        $username = $this->option('target-username');
        $password = $this->option('target-password');
        $port = $this->option('target-port');
        
        // Interactive mode if not all options provided
        if (!$host || !$database || !$username || !$password) {
            $this->info('Enter target database configuration:');
            
            $host = $host ?: $this->ask('Database host', 'localhost');
            $database = $database ?: $this->ask('Database name');
            $username = $username ?: $this->ask('Database username');
            $password = $password ?: $this->secret('Database password');
            $port = $port ?: $this->ask('Database port', 3306);
        }
        
        // Validate required fields
        if (!$host || !$database || !$username || !$password) {
            $this->error('All target database credentials are required.');
            return null;
        }
        
        return [
            'host' => $host,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'port' => $port,
        ];
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
                $s3Backups = $this->backupService->listS3Backups();
                $found = null;
                
                foreach ($s3Backups as $backup) {
                    if ($backup['filename'] === $backupFile || $backup['path'] === $backupFile) {
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
     * Anonymize sensitive data in the restored database
     */
    protected function anonymizeData(array $targetConfig): void
    {
        $this->info('Anonymizing sensitive data...');
        
        try {
            // Connect to target database
            $pdo = new \PDO(
                "mysql:host={$targetConfig['host']};port={$targetConfig['port']};dbname={$targetConfig['database']}",
                $targetConfig['username'],
                $targetConfig['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            
            // Anonymize user emails (keep domain for testing)
            $pdo->exec("UPDATE users SET email = CONCAT('user', id, '@staging.local') WHERE email NOT LIKE '%@staging.local'");
            
            // Anonymize user passwords (set to default staging password)
            $stagingPassword = bcrypt('staging123');
            $pdo->exec("UPDATE users SET password = '$stagingPassword'");
            
            // Clear sensitive logs
            $pdo->exec("TRUNCATE TABLE audits");
            $pdo->exec("TRUNCATE TABLE backup_logs");
            
            // Update environment-specific settings if needed
            // Add more anonymization as needed based on your requirements
            
            $this->info('Data anonymization completed.');
            
        } catch (\Exception $e) {
            $this->warn('Failed to anonymize data: ' . $e->getMessage());
            $this->warn('You may need to manually anonymize sensitive data.');
        }
    }
    
    /**
     * Log the staging operation
     */
    protected function logOperation($backupFile, array $targetConfig, float $duration): void
    {
        try {
            BackupLog::create([
                'type' => 'staging_restore',
                'frequency' => 'manual',
                'status' => 'success',
                'filename' => basename($backupFile),
                'location' => 'staging',
                'started_at' => now()->subSeconds($duration),
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'metadata' => [
                    'source_backup' => $backupFile,
                    'target_host' => $targetConfig['host'],
                    'target_database' => $targetConfig['database'],
                    'environment_name' => $this->option('env-name'),
                    'anonymized' => $this->option('anonymize'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log staging operation', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Display configuration before execution
     */
    protected function displayConfiguration($backupFile, array $targetConfig): void
    {
        $this->info('Staging Environment Configuration:');
        $this->info('==================================');
        
        $this->table(
            ['Setting', 'Value'],
            [
                ['Source Backup', basename($backupFile)],
                ['Environment Name', $this->option('env-name') ?: 'Not specified'],
                ['Target Host', $targetConfig['host']],
                ['Target Database', $targetConfig['database']],
                ['Target Username', $targetConfig['username']],
                ['Target Port', $targetConfig['port']],
                ['Create Database', $this->option('create-database') ? 'Yes' : 'No'],
                ['Anonymize Data', $this->option('anonymize') ? 'Yes' : 'No'],
                ['Decrypt Backup', $this->option('decrypt') ? 'Yes' : 'No'],
            ]
        );
    }
    
    /**
     * Display operation summary
     */
    protected function displaySummary(array $targetConfig, float $duration): void
    {
        $this->newLine();
        $this->info('✅ Staging environment created successfully!');
        $this->info('=====================================');
        
        $this->table(
            ['Property', 'Value'],
            [
                ['Database Host', $targetConfig['host']],
                ['Database Name', $targetConfig['database']],
                ['Duration', $duration . ' seconds'],
                ['Environment', $this->option('env-name') ?: 'staging'],
            ]
        );
        
        $this->newLine();
        $this->info('Connection string:');
        $this->line("mysql -h {$targetConfig['host']} -P {$targetConfig['port']} -u {$targetConfig['username']} -p {$targetConfig['database']}");
        
        if ($this->option('anonymize')) {
            $this->newLine();
            $this->info('Note: All user passwords have been set to: staging123');
        }
    }
}