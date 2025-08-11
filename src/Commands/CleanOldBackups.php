<?php

namespace MyDatabase\BackupRestore\Commands;

use MyDatabase\BackupRestore\Models\BackupLog;
use MyDatabase\BackupRestore\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanOldBackups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:clean 
                            {--type= : Backup type (database, files, uploads, all)}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean old backups according to retention policy';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');
        $dryRun = $this->option('dry-run');
        
        $this->info('Cleaning old backups according to retention policy...');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }
        
        $types = $type === 'all' || !$type ? ['database', 'files', 'uploads'] : [$type];
        $frequencies = ['daily', 'weekly', 'monthly'];
        
        $totalCleaned = 0;
        $totalSpaceFreed = 0;
        
        foreach ($types as $backupType) {
            $this->newLine();
            $this->info("Processing $backupType backups:");
            
            foreach ($frequencies as $frequency) {
                $retentionDays = config('backup.retention.' . $frequency, 7);
                $cutoffDate = Carbon::now()->subDays($retentionDays);
                
                // Get old backups
                $oldBackups = BackupLog::where('type', $backupType)
                    ->where('frequency', $frequency)
                    ->where('started_at', '<', $cutoffDate)
                    ->successful()
                    ->whereNull('metadata->cleaned_at')
                    ->get();
                
                if ($oldBackups->isEmpty()) {
                    continue;
                }
                
                $this->info("  $frequency backups (retention: $retentionDays days):");
                $this->info("  Found {$oldBackups->count()} backups older than {$cutoffDate->format('Y-m-d')}");
                
                foreach ($oldBackups as $backup) {
                    $size = $backup->size_bytes;
                    $totalSpaceFreed += $size;
                    
                    $this->info(sprintf(
                        "    - %s (%s, %s)",
                        $backup->filename,
                        $backup->formatted_size,
                        $backup->started_at->format('Y-m-d H:i')
                    ));
                    
                    if (!$dryRun) {
                        // Mark as cleaned (automatic cleanup will handle actual deletion)
                        $backup->update([
                            'metadata' => array_merge($backup->metadata ?? [], [
                                'cleaned_at' => now()->toDateTimeString(),
                                'cleaned_by' => 'manual_command',
                            ]),
                        ]);
                        
                        $totalCleaned++;
                    }
                }
            }
        }
        
        $this->newLine();
        $this->info('Summary:');
        $this->info('--------');
        
        if ($dryRun) {
            $this->info("Would delete $totalCleaned backup(s)");
            $this->info("Would free " . $this->formatBytes($totalSpaceFreed));
        } else {
            $this->info("Marked $totalCleaned backup(s) for cleanup");
            $this->info("Space to be freed: " . $this->formatBytes($totalSpaceFreed));
            
            Log::info('Manual backup cleanup completed', [
                'cleaned_count' => $totalCleaned,
                'space_freed' => $totalSpaceFreed,
            ]);
        }
        
        return 0;
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
