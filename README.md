# Laravel Backup & Restore Package

<p align="center">
<a href="https://packagist.org/packages/mydatabase/laravel-backup-restore"><img src="https://img.shields.io/packagist/v/mydatabase/laravel-backup-restore" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/mydatabase/laravel-backup-restore"><img src="https://img.shields.io/packagist/l/mydatabase/laravel-backup-restore" alt="License"></a>
</p>

A comprehensive, enterprise-grade backup and restore solution for Laravel applications with S3 support, encryption, and staging environment creation capabilities.

## ✨ Features

- 🔒 **AES-256-CBC Encryption** - Secure your backups with military-grade encryption
- ☁️ **S3 Integration** - Store backups on AWS S3 or compatible storage (OVH, MinIO, etc.)
- 🔄 **Complete Backup/Restore** - Database, application files, and user uploads
- 🚀 **Staging Environments** - Create staging databases from production backups
- 📊 **Backup Management** - List, download, and clean old backups automatically
- 🎯 **Interactive Commands** - User-friendly CLI with progress bars
- 📝 **Audit Trail** - Complete logging of all backup operations
- ⚡ **Performance** - Streaming support for large files, chunked operations
- 🔧 **Extensible** - Events, custom handlers, and configuration options

## 📋 Requirements

- PHP 8.1 or higher
- Laravel 10.0 or higher
- MySQL 5.7+ / MariaDB 10.3+ / PostgreSQL 12+
- Composer 2.0+
- Optional: AWS S3 or S3-compatible storage

## 🚀 Installation

### Step 1: Install via Composer

```bash
composer require PhilCANDIDO/laravel-backup-restore
```

### Step 2: Publish Assets

```bash
# Publish configuration file
php artisan vendor:publish --tag=backup-config

# Publish migrations
php artisan vendor:publish --tag=backup-migrations

# Publish scripts (optional)
php artisan vendor:publish --tag=backup-scripts
```

### Step 3: Run Migrations

```bash
php artisan migrate
```

### Step 4: Configure Environment

Add these variables to your `.env` file:

```env
# Basic Configuration
BACKUP_ENABLED=true
BACKUP_DRIVER=local  # Options: local, s3

# Local Storage (optional)
BACKUP_LOCAL_PATH=/path/to/backups  # Default: storage/app/backups

# S3 Configuration (required if BACKUP_DRIVER=s3)
S3_BACKUP_ENDPOINT=https://s3.amazonaws.com
S3_BACKUP_REGION=us-east-1
S3_BACKUP_KEY=your-access-key-id
S3_BACKUP_SECRET=your-secret-access-key
S3_BACKUP_BUCKET=your-backup-bucket

# Encryption (highly recommended for production)
BACKUP_ENCRYPTION_ENABLED=true
BACKUP_ENCRYPTION_PASSWORD=your-secure-64-character-password-here

# Retention Policy (in days)
BACKUP_RETENTION_DAILY=7    # Keep daily backups for 7 days
BACKUP_RETENTION_WEEKLY=4   # Keep weekly backups for 4 weeks  
BACKUP_RETENTION_MONTHLY=6  # Keep monthly backups for 6 months
```

## 📚 Usage

### Basic Commands

#### Create Backups

```bash
# Backup everything (database + files + uploads)
php artisan backup:all

# Backup specific components
php artisan backup:database
php artisan backup:files
php artisan backup:uploads

# Scheduled backups
php artisan backup:database --type=daily
php artisan backup:all --type=weekly
```

#### List Backups

```bash
# List all backups (auto-detects local or S3)
php artisan backup:list

# List only S3 backups
php artisan backup:list --s3

# Filter by type
php artisan backup:list --type=database

# Output as JSON
php artisan backup:list --format=json
```

#### Restore Backups

```bash
# Interactive restore (with menu)
php artisan backup:restore --interactive

# Restore specific file
php artisan backup:restore --file=backup_2025_08_11.sql.gz

# Restore with download from S3 and decryption
php artisan backup:restore --interactive --download --decrypt

# Force restore without confirmations
php artisan backup:restore --file=backup.sql.gz --force
```

#### Create Staging Environment

```bash
# Basic staging setup
php artisan backup:create-staging \
    --from=production_backup.sql.gz \
    --target-database=staging_db

# Full staging with all options
php artisan backup:create-staging \
    --from=production_backup.sql.gz \
    --target-host=staging.example.com \
    --target-database=staging_db \
    --target-username=staging_user \
    --target-password=staging_pass \
    --target-port=3306 \
    --create-database \
    --anonymize \
    --download \
    --decrypt
```

#### Download from S3

```bash
# Download latest backup
php artisan backup:download --latest

# Download specific file
php artisan backup:download --file=backup.sql.gz

# Download and decrypt
php artisan backup:download --latest --decrypt

# Download and delete from S3
php artisan backup:download --file=backup.sql.gz --delete-remote
```

#### Clean Old Backups

```bash
# Clean according to retention policy
php artisan backup:clean

# Dry run (preview what would be deleted)
php artisan backup:clean --dry-run

# Clean specific type only
php artisan backup:clean --type=database
```

### 🎨 Using the Facade

```php
use MyDatabase\BackupRestore\Facades\Backup;

// Create backups
$backupLog = Backup::backupDatabase('daily');
$backupLog = Backup::backupFiles('manual');

// List backups
$localBackups = Backup::listLocalBackups();
$s3Backups = Backup::listS3Backups('database');

// Restore
Backup::restoreDatabase('/path/to/backup.sql.gz', true, 'password');

// Download from S3
$localPath = Backup::downloadFromS3('database/daily/backup.sql.gz');

// Encryption utilities
$password = Backup::generateEncryptionPassword();
$isEncrypted = Backup::isFileEncrypted('/path/to/file');
```

### 📅 Automation

#### Using Cron

Add to your crontab (`crontab -e`):

```bash
# Daily backup at 2:00 AM
0 2 * * * cd /path/to/project && php artisan backup:all --type=daily >> /dev/null 2>&1

# Weekly backup on Sundays at 3:00 AM
0 3 * * 0 cd /path/to/project && php artisan backup:all --type=weekly >> /dev/null 2>&1

# Monthly backup on 1st at 4:00 AM
0 4 1 * * cd /path/to/project && php artisan backup:all --type=monthly >> /dev/null 2>&1

# Clean old backups daily at 5:00 AM
0 5 * * * cd /path/to/project && php artisan backup:clean >> /dev/null 2>&1
```

#### Using Laravel Scheduler

In `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Daily database backup at 2:00 AM
    $schedule->command('backup:database --type=daily')
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/backup.log'));

    // Weekly full backup on Sundays
    $schedule->command('backup:all --type=weekly')
        ->weeklyOn(0, '03:00')
        ->withoutOverlapping();

    // Clean old backups
    $schedule->command('backup:clean')
        ->dailyAt('05:00');
}
```

Don't forget to add the Laravel scheduler to cron:

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## 🔧 Advanced Configuration

### Custom Backup Directories

```php
// config/backup.php
return [
    'local' => [
        'path' => env('BACKUP_LOCAL_PATH', storage_path('custom-backups')),
    ],
    // ...
];
```

### Extending the Service

Create a custom backup service:

```php
use MyDatabase\BackupRestore\Services\BackupService;

class CustomBackupService extends BackupService
{
    public function backupCustomData(): BackupLog
    {
        // Your custom backup logic
    }
}

// Register in a service provider
$this->app->singleton(BackupService::class, CustomBackupService::class);
```

### Events

Listen to backup events:

```php
use MyDatabase\BackupRestore\Events\BackupCompleted;
use MyDatabase\BackupRestore\Events\BackupFailed;
use MyDatabase\BackupRestore\Events\RestorationCompleted;

// In EventServiceProvider
protected $listen = [
    BackupCompleted::class => [
        SendBackupNotification::class,
        UpdateBackupStatistics::class,
    ],
    BackupFailed::class => [
        AlertAdministrators::class,
    ],
];
```

## 🔒 Security Best Practices

1. **Always use encryption for production backups**
   ```bash
   php artisan tinker
   >>> bin2hex(random_bytes(32))  # Generate secure password
   ```

2. **Store encryption passwords securely**
   - Use Laravel's encrypted environment variables
   - Consider using AWS Secrets Manager or HashiCorp Vault

3. **Implement access controls**
   - Use IAM roles for S3 access
   - Restrict backup commands to admin users
   - Enable IP whitelisting for sensitive operations

4. **Regular testing**
   - Test restore procedures monthly
   - Verify backup integrity
   - Document recovery procedures

## 🧪 Testing

Run the test suite:

```bash
composer test

# With coverage
composer test-coverage

# Code style
composer format
```

## 🐛 Troubleshooting

### Common Issues

**S3 Upload Fails**
```bash
# Check credentials
php artisan tinker
>>> config('backup.s3')

# Test S3 connection
php artisan backup:list --s3
```

**Encryption Issues**
```bash
# Verify encryption password is set
php artisan tinker
>>> config('backup.encryption.password')

# Test decryption
php artisan backup:decrypt storage/app/backups/file.enc
```

**Memory Issues**
```ini
; php.ini
memory_limit = 512M
max_execution_time = 0
```

## 📄 License

The Laravel Backup & Restore package is open-sourced software licensed under the [MIT license](LICENSE.md).

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## 🔐 Security

If you discover any security-related issues, please email support@emerging-it.fr instead of using the issue tracker.

## 👥 Credits

- [Philippe Candido](https://github.com/PhilCANDIDO)
- [All Contributors](../../contributors)

## 📚 Documentation

For detailed documentation, visit [https://docs.mydatabase.com/backup-restore](https://docs.mydatabase.com/backup-restore)

## 💬 Support

- Documentation: [https://docs.mydatabase.com](https://docs.mydatabase.com)
- Issues: [GitHub Issues](https://github.com/PhilCANDIDO/laravel-backup-restore.git/issues)
- Discussions: [GitHub Discussions](https://github.com/PhilCANDIDO/laravel-backup-restore.git/discussions)
- Email: support@emerging-it.fr