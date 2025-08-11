<?php

namespace MyDatabase\BackupRestore\Tests\Feature;

use MyDatabase\BackupRestore\Tests\TestCase;
use MyDatabase\BackupRestore\Services\BackupService;
use MyDatabase\BackupRestore\Models\BackupLog;
use Illuminate\Support\Facades\Storage;

class BackupServiceTest extends TestCase
{
    protected BackupService $backupService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->backupService = new BackupService();
        
        // Create test backup directory
        $backupPath = sys_get_temp_dir() . '/backup-tests';
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test backup directory
        $backupPath = sys_get_temp_dir() . '/backup-tests';
        if (is_dir($backupPath)) {
            array_map('unlink', glob("$backupPath/*"));
            rmdir($backupPath);
        }
        
        parent::tearDown();
    }

    /** @test */
    public function it_can_list_local_backups()
    {
        // Create some test backup files
        $backupPath = sys_get_temp_dir() . '/backup-tests';
        touch("$backupPath/mydatabase_backup_database_2025_08_11_120000.sql.gz");
        touch("$backupPath/mydatabase_backup_files_2025_08_11_130000.tar.gz");
        
        $backups = $this->backupService->listLocalBackups();
        
        $this->assertIsArray($backups);
        $this->assertCount(2, $backups);
        $this->assertEquals('database', $backups[0]['type']);
        $this->assertEquals('files', $backups[1]['type']);
    }

    /** @test */
    public function it_can_detect_encrypted_files()
    {
        $backupPath = sys_get_temp_dir() . '/backup-tests';
        
        // Create regular file
        $regularFile = "$backupPath/test.sql.gz";
        file_put_contents($regularFile, 'test content');
        
        // Create encrypted file (with .enc extension)
        $encryptedFile = "$backupPath/test.sql.gz.enc";
        file_put_contents($encryptedFile, 'Salted__' . random_bytes(100));
        
        $this->assertFalse(BackupService::isFileEncrypted($regularFile));
        $this->assertTrue(BackupService::isFileEncrypted($encryptedFile));
    }

    /** @test */
    public function it_can_generate_encryption_password()
    {
        $password = BackupService::generateEncryptionPassword();
        
        $this->assertIsString($password);
        $this->assertEquals(64, strlen($password)); // 32 bytes = 64 hex characters
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $password);
    }

    /** @test */
    public function it_creates_backup_log_entries()
    {
        // This would need database setup for full testing
        $this->assertDatabaseCount('backup_logs', 0);
        
        BackupLog::create([
            'type' => 'database',
            'frequency' => 'manual',
            'status' => 'success',
            'filename' => 'test_backup.sql.gz',
            'location' => 'local',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        
        $this->assertDatabaseCount('backup_logs', 1);
    }

    /** @test */
    public function it_can_filter_backups_by_type()
    {
        $backupPath = sys_get_temp_dir() . '/backup-tests';
        touch("$backupPath/mydatabase_backup_database_2025_08_11_120000.sql.gz");
        touch("$backupPath/mydatabase_backup_files_2025_08_11_130000.tar.gz");
        touch("$backupPath/mydatabase_backup_uploads_2025_08_11_140000.tar.gz");
        
        $databaseBackups = $this->backupService->listLocalBackups('database');
        $filesBackups = $this->backupService->listLocalBackups('files');
        
        $this->assertCount(1, $databaseBackups);
        $this->assertCount(1, $filesBackups);
        $this->assertEquals('database', $databaseBackups[0]['type']);
        $this->assertEquals('files', $filesBackups[0]['type']);
    }
}