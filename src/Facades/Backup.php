<?php

namespace MyDatabase\BackupRestore\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \MyDatabase\BackupRestore\Models\BackupLog backupDatabase(string $frequency = 'manual')
 * @method static \MyDatabase\BackupRestore\Models\BackupLog backupFiles(string $frequency = 'manual')
 * @method static \MyDatabase\BackupRestore\Models\BackupLog backupUploads(string $frequency = 'manual')
 * @method static array listLocalBackups(string|null $type = null)
 * @method static array listS3Backups(string|null $type = null)
 * @method static string downloadFromS3(string $s3Path, string|null $localPath = null)
 * @method static void restoreDatabase(string $backupFile, bool $decrypt = false, string|null $password = null)
 * @method static void restoreToDifferentDatabase(string $backupFile, array $targetConfig, bool $decrypt = false, string|null $password = null)
 * @method static string decryptFile(string $encryptedPath, string $password, string|null $outputPath = null)
 * @method static bool isFileEncrypted(string $filepath)
 * @method static string generateEncryptionPassword()
 * 
 * @see \MyDatabase\BackupRestore\Services\BackupService
 */
class Backup extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'backup';
    }
}