# Installation Guide

## Quick Start

### 1. Add Package to Your Laravel Project

#### Option A: From Packagist (once published)
```bash
composer require mydatabase/laravel-backup-restore
```

#### Option B: From GitHub
Add to your `composer.json`:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/mydatabase/laravel-backup-restore.git"
        }
    ],
    "require": {
        "mydatabase/laravel-backup-restore": "dev-main"
    }
}
```

Then run:
```bash
composer update
```

#### Option C: Local Development
For local development, add to your Laravel project's `composer.json`:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-backup-restore"
        }
    ],
    "require": {
        "mydatabase/laravel-backup-restore": "@dev"
    }
}
```

### 2. Publish Configuration

```bash
# Publish config file (required)
php artisan vendor:publish --provider="MyDatabase\BackupRestore\BackupRestoreServiceProvider" --tag="backup-config"

# Publish migrations (required)
php artisan vendor:publish --provider="MyDatabase\BackupRestore\BackupRestoreServiceProvider" --tag="backup-migrations"

# Publish scripts (optional - for cron jobs)
php artisan vendor:publish --provider="MyDatabase\BackupRestore\BackupRestoreServiceProvider" --tag="backup-scripts"
```

### 3. Configure Environment Variables

Add to your `.env` file:

```env
# Minimum configuration
BACKUP_ENABLED=true
BACKUP_DRIVER=local

# For S3 storage
BACKUP_DRIVER=s3
S3_BACKUP_ENDPOINT=https://s3.amazonaws.com
S3_BACKUP_REGION=us-east-1
S3_BACKUP_KEY=your-key
S3_BACKUP_SECRET=your-secret
S3_BACKUP_BUCKET=your-bucket

# For encryption (recommended)
BACKUP_ENCRYPTION_ENABLED=true
BACKUP_ENCRYPTION_PASSWORD=generate-64-char-password-here
```

### 4. Run Migrations

```bash
php artisan migrate
```

### 5. Verify Installation

```bash
# List available commands
php artisan list backup

# Test backup creation
php artisan backup:database

# Check if backup was created
php artisan backup:list
```

## Advanced Configuration

### Custom Backup Path

Edit `config/backup.php`:
```php
'local' => [
    'path' => env('BACKUP_LOCAL_PATH', storage_path('app/backups')),
],
```

### Retention Policy

Configure how long to keep backups:
```env
BACKUP_RETENTION_DAILY=7    # Days
BACKUP_RETENTION_WEEKLY=4   # Weeks
BACKUP_RETENTION_MONTHLY=6  # Months
```

### Generate Encryption Password

```bash
php artisan tinker
>>> bin2hex(random_bytes(32))
"copy-this-64-character-string-to-your-env-file"
```

## Setting Up Automation

### Using Cron

```bash
# Edit crontab
crontab -e

# Add daily backup at 2 AM
0 2 * * * cd /path/to/your/app && php artisan backup:all --type=daily >> /dev/null 2>&1
```

### Using Laravel Scheduler

In `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('backup:database --type=daily')
        ->dailyAt('02:00');
}
```

Then add to cron:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Troubleshooting

### Permission Issues

```bash
# Ensure storage is writable
chmod -R 755 storage
chown -R www-data:www-data storage
```

### Memory Limits

For large databases, increase PHP memory limit:
```ini
; php.ini
memory_limit = 512M
max_execution_time = 0
```

### S3 Connection Issues

Test S3 connection:
```bash
php artisan backup:list --s3
```

If it fails, check:
1. Credentials are correct
2. Bucket exists and is accessible
3. IAM permissions include s3:PutObject, s3:GetObject, s3:DeleteObject
4. Network allows connection to S3 endpoint

## Next Steps

1. Test backup creation: `php artisan backup:database`
2. Test restoration: `php artisan backup:restore --interactive`
3. Set up monitoring for backup failures
4. Document your disaster recovery procedures
5. Test recovery procedures regularly

## Support

- GitHub Issues: [https://github.com/mydatabase/laravel-backup-restore/issues](https://github.com/mydatabase/laravel-backup-restore/issues)
- Documentation: [Full documentation](README.md)