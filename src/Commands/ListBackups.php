<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Services\BackupService;
use Illuminate\Console\Command;

class ListBackups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:list 
                            {--local : List local backups only}
                            {--s3 : List S3 backups only}
                            {--type= : Filter by backup type (database, files, uploads)}
                            {--format=table : Output format (table or json)}
                            {--limit=50 : Maximum number of backups to display}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List available backup files from local storage or S3';

    protected BackupService $backupService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->backupService = new BackupService();
        
        $format = $this->option('format');
        $type = $this->option('type');
        $limit = (int) $this->option('limit');
        
        // Determine which backups to list
        $listLocal = $this->option('local');
        $listS3 = $this->option('s3');
        
        // If neither specified, determine based on config
        if (!$listLocal && !$listS3) {
            $driver = config('backup.driver', 'local');
            if ($driver === 's3') {
                $listS3 = true;
            } else {
                $listLocal = true;
            }
        }
        
        $allBackups = [];
        
        try {
            // List local backups
            if ($listLocal) {
                $this->info('Fetching local backups...');
                $localBackups = $this->backupService->listLocalBackups($type);
                $allBackups = array_merge($allBackups, $localBackups);
            }
            
            // List S3 backups
            if ($listS3) {
                $this->info('Fetching S3 backups...');
                try {
                    $s3Backups = $this->backupService->listS3Backups($type);
                    $allBackups = array_merge($allBackups, $s3Backups);
                } catch (\Exception $e) {
                    $this->warn('Failed to fetch S3 backups: ' . $e->getMessage());
                    if (!$listLocal) {
                        return 1;
                    }
                }
            }
            
            // Sort all backups by created_at descending
            usort($allBackups, function($a, $b) {
                return $b['created_at'] - $a['created_at'];
            });
            
            // Apply limit
            if ($limit > 0 && count($allBackups) > $limit) {
                $allBackups = array_slice($allBackups, 0, $limit);
            }
            
            // Display results
            if (empty($allBackups)) {
                $this->warn('No backups found.');
                return 0;
            }
            
            if ($format === 'json') {
                $this->displayJson($allBackups);
            } else {
                $this->displayTable($allBackups);
            }
            
            // Display summary
            $this->displaySummary($allBackups);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Failed to list backups: ' . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Display backups in table format
     */
    protected function displayTable(array $backups): void
    {
        $this->newLine();
        $this->info('Available Backups:');
        $this->info('==================');
        
        $headers = ['Filename', 'Type', 'Size', 'Created', 'Location', 'Encrypted'];
        $rows = [];
        
        foreach ($backups as $backup) {
            $rows[] = [
                $backup['filename'],
                ucfirst($backup['type']),
                $backup['size_human'],
                $backup['created_at_human'],
                strtoupper($backup['location']),
                $backup['encrypted'] ? '🔒 Yes' : 'No',
            ];
        }
        
        $this->table($headers, $rows);
    }
    
    /**
     * Display backups in JSON format
     */
    protected function displayJson(array $backups): void
    {
        $this->line(json_encode($backups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Display summary statistics
     */
    protected function displaySummary(array $backups): void
    {
        $this->newLine();
        
        // Calculate statistics
        $totalSize = array_sum(array_column($backups, 'size'));
        $typeCount = array_count_values(array_column($backups, 'type'));
        $locationCount = array_count_values(array_column($backups, 'location'));
        $encryptedCount = count(array_filter($backups, fn($b) => $b['encrypted']));
        
        // Get latest backup per type
        $latestByType = [];
        foreach ($backups as $backup) {
            $type = $backup['type'];
            if (!isset($latestByType[$type]) || $backup['created_at'] > $latestByType[$type]['created_at']) {
                $latestByType[$type] = $backup;
            }
        }
        
        $this->info('Summary:');
        $this->info('--------');
        
        $summaryData = [
            ['Total Backups', count($backups)],
            ['Total Size', $this->formatBytes($totalSize)],
            ['Encrypted', $encryptedCount . ' / ' . count($backups)],
        ];
        
        // Add type breakdown
        foreach ($typeCount as $type => $count) {
            $summaryData[] = [ucfirst($type) . ' Backups', $count];
        }
        
        // Add location breakdown
        foreach ($locationCount as $location => $count) {
            $summaryData[] = [strtoupper($location) . ' Storage', $count];
        }
        
        $this->table(['Property', 'Value'], $summaryData);
        
        // Display latest backups
        if (!empty($latestByType)) {
            $this->newLine();
            $this->info('Latest Backups by Type:');
            $this->info('-----------------------');
            
            $latestData = [];
            foreach ($latestByType as $type => $backup) {
                $age = $this->getAgeString($backup['created_at']);
                $latestData[] = [
                    ucfirst($type),
                    $backup['filename'],
                    $backup['created_at_human'],
                    $age,
                ];
            }
            
            $this->table(['Type', 'Filename', 'Created', 'Age'], $latestData);
        }
        
        // Check for old backups
        $this->checkOldBackups($backups);
    }
    
    /**
     * Check for old backups and display warnings
     */
    protected function checkOldBackups(array $backups): void
    {
        $now = time();
        $oneWeekAgo = $now - (7 * 24 * 60 * 60);
        $oneMonthAgo = $now - (30 * 24 * 60 * 60);
        
        $oldBackups = array_filter($backups, fn($b) => $b['created_at'] < $oneMonthAgo);
        $recentBackups = array_filter($backups, fn($b) => $b['created_at'] > $oneWeekAgo);
        
        if (count($oldBackups) > 0) {
            $this->newLine();
            $this->warn('⚠️  ' . count($oldBackups) . ' backup(s) are older than 30 days and may be eligible for cleanup.');
        }
        
        if (count($recentBackups) === 0) {
            $this->newLine();
            $this->warn('⚠️  No backups created in the last 7 days. Consider running a backup soon.');
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
        } elseif ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . ' week' . ($weeks !== 1 ? 's' : '') . ' ago';
        } else {
            $months = floor($diff / 2592000);
            return $months . ' month' . ($months !== 1 ? 's' : '') . ' ago';
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