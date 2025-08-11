# Backup and Monitoring System Documentation

## Table of Contents
- [Overview](#overview)
- [Configuration](#configuration)
- [Backup Commands](#backup-commands)
- [Monitoring API](#monitoring-api)
- [Automation](#automation)
- [Restoration](#restoration)
- [Troubleshooting](#troubleshooting)

---

## Overview

The MyDatabase backup system provides comprehensive backup capabilities for:
- **Database**: Complete MySQL/MariaDB database dumps
- **Application Files**: Source code and configuration files
- **User Uploads**: Files uploaded by users (storage/app/public)

Backups can be stored locally or on S3-compatible storage (like OVH Object Storage) with automatic retention management.

---

## Configuration

### Environment Variables (.env)

```env
# Basic Backup Configuration
BACKUP_ENABLED=true                    # Enable/disable backup system
BACKUP_DRIVER=local                     # Storage driver: 'local' or 's3'
BACKUP_LOCAL_PATH=/path/to/backups     # Local backup directory (optional)

# Retention Policy (in days)
BACKUP_RETENTION_DAILY=7                # Keep daily backups for 7 days
BACKUP_RETENTION_WEEKLY=4               # Keep weekly backups for 4 weeks
BACKUP_RETENTION_MONTHLY=6              # Keep monthly backups for 6 months

# S3/OVH Configuration (required if BACKUP_DRIVER=s3)
S3_BACKUP_ENDPOINT=https://s3.gra.io.cloud.ovh.net
S3_BACKUP_REGION=gra
S3_BACKUP_KEY=your-access-key
S3_BACKUP_SECRET=your-secret-key
S3_BACKUP_BUCKET=mydatabase-backups

# Monitoring API Configuration
MONITORING_ENABLED=true
MONITORING_API_KEY=your-secure-api-key-here
MONITORING_ALLOWED_IPS=192.168.1.100,10.0.0.5  # Optional IP whitelist
```

### Storage Drivers

#### Local Storage (Default)
```env
BACKUP_DRIVER=local
BACKUP_LOCAL_PATH=/var/backups/mydatabase  # Optional, defaults to storage/app/backups
```

#### S3/OVH Object Storage
```env
BACKUP_DRIVER=s3
# S3 credentials required (see above)
```

### Encryption Configuration (Highly Recommended)

Protect your backups with AES-256-CBC encryption:

```env
# Enable backup encryption
BACKUP_ENCRYPTION_ENABLED=true
BACKUP_ENCRYPTION_PASSWORD=your-secure-64-character-password-here
```

Generate a secure password:
```bash
./vendor/bin/sail php artisan tinker --execute="echo App\Services\BackupService::generateEncryptionPassword();"
```

---

## Backup Commands

### 1. Database Backup

Backs up the entire database to a compressed SQL file.

```bash
# Manual backup
./vendor/bin/sail php artisan backup:database

# Scheduled backup (for automation)
./vendor/bin/sail php artisan backup:database --type=daily
./vendor/bin/sail php artisan backup:database --type=weekly
./vendor/bin/sail php artisan backup:database --type=monthly
```

**Output Example:**
```
Starting database backup...
Type: manual
Database backup completed successfully!
+----------+-----------------------------------------------------+
| Property | Value                                               |
+----------+-----------------------------------------------------+
| Filename | mydatabase_backup_database_2025-08-08_181210.sql.gz |
| Size     | 455.78 KB                                           |
| Duration | 1 seconds                                           |
| Location | s3://mydatabase-backups/database/manual/...        |
+----------+-----------------------------------------------------+
```

### 2. Application Files Backup

Backs up source code and configuration files.

```bash
# Backup application files
./vendor/bin/sail php artisan backup:files

# With frequency type
./vendor/bin/sail php artisan backup:files --type=weekly
```

**What's included:**
- `/app` - Application code
- `/config` - Configuration files
- `/database` - Migrations and seeders
- `/resources` - Views and assets
- `/routes` - Route definitions

### 3. User Uploads Backup

Backs up user-uploaded files and media.

```bash
# Backup uploads
./vendor/bin/sail php artisan backup:uploads

# With frequency type
./vendor/bin/sail php artisan backup:uploads --type=daily
```

**What's included:**
- `/storage/app/public` - All user uploads
- Product images
- File attachments

### 4. Complete Backup (All)

Runs all three backup types in sequence.

```bash
# Backup everything
./vendor/bin/sail php artisan backup:all

# With frequency type
./vendor/bin/sail php artisan backup:all --type=daily
```

**Output Example:**
```
Starting complete backup...
Type: manual

Backing up database...
   Database backup completed

Backing up application files...
   Application files backup completed

Backing up uploads...
   Uploads backup completed

Backup Summary:
----------------------------------------
Database: Success
  File: mydatabase_backup_database_2025-08-08_181210.sql.gz
  Size: 455.78 KB
  Duration: 1s
Files: Success
  File: mydatabase_backup_files_2025-08-08_181215.tar.gz
  Size: 12.34 MB
  Duration: 3s
Uploads: Success
  File: mydatabase_backup_uploads_2025-08-08_181218.tar.gz
  Size: 234.56 MB
  Duration: 15s

Complete backup completed successfully!
```

### 5. Clean Old Backups

Manually clean backups according to retention policy.

```bash
# Preview what would be deleted (dry run)
./vendor/bin/sail php artisan backup:clean --dry-run

# Actually clean old backups
./vendor/bin/sail php artisan backup:clean

# Clean specific type only
./vendor/bin/sail php artisan backup:clean --type=database
./vendor/bin/sail php artisan backup:clean --type=files
./vendor/bin/sail php artisan backup:clean --type=uploads
```

### 6. Decrypt Encrypted Backups

Decrypt backup files that were encrypted during backup.

```bash
# Interactive mode (prompts for password)
./vendor/bin/sail php artisan backup:decrypt storage/app/backups/backup.sql.gz.enc

# With password in command
./vendor/bin/sail php artisan backup:decrypt storage/app/backups/backup.sql.gz.enc \
  --password=YOUR_ENCRYPTION_PASSWORD

# Specify output location
./vendor/bin/sail php artisan backup:decrypt storage/app/backups/backup.sql.gz.enc \
  --output=/tmp/decrypted_backup.sql.gz
```

**Output Example:**
```
Cleaning old backups according to retention policy...

Processing database backups:
  daily backups (retention: 7 days):
  Found 3 backups older than 2025-08-01
    - mydatabase_backup_database_2025-07-30_020000.sql.gz (455.23 KB, 2025-07-30 02:00)
    - mydatabase_backup_database_2025-07-31_020000.sql.gz (456.12 KB, 2025-07-31 02:00)

Summary:
--------
Marked 2 backup(s) for cleanup
Space to be freed: 911.35 KB
```

---

## Monitoring API

### Available Endpoints

#### Public Health Check (No Authentication)
```bash
curl http://localhost/api/health
```

Response:
```json
{
  "status": "healthy",
  "timestamp": "2025-08-08T18:30:00Z",
  "service": "MyDatabase"
}
```

#### Protected Endpoints (API Key Required)

##### 1. Detailed Health Check
```bash
curl -H "X-API-Key: YOUR_API_KEY" \
  http://localhost/api/monitoring/detailed-health
```

Response:
```json
{
  "status": "healthy",
  "timestamp": "2025-08-08T18:30:00Z",
  "checks": {
    "database": {
      "status": "healthy",
      "response_time_ms": 15.2,
      "details": {
        "connection": "active",
        "migrations": "up-to-date",
        "tables_count": 25
      }
    },
    "storage": {
      "status": "healthy",
      "details": {
        "disk_usage_percent": 45.3,
        "available_gb": 120.5
      }
    },
    "cache": {
      "status": "healthy",
      "details": {
        "driver": "redis",
        "connection": "active"
      }
    },
    "queue": {
      "status": "healthy",
      "details": {
        "pending_jobs": 0,
        "failed_jobs": 0
      }
    }
  }
}
```

##### 2. Backup Status
```bash
curl -H "X-API-Key: YOUR_API_KEY" \
  http://localhost/api/monitoring/backup-status
```

Response:
```json
{
  "last_backup": {
    "database": {
      "timestamp": "2025-08-08T02:00:00Z",
      "status": "success",
      "size_mb": 250.5,
      "duration_seconds": 45,
      "backup_age_hours": 16
    },
    "files": {
      "timestamp": "2025-08-07T03:00:00Z",
      "status": "success",
      "size_mb": 12.3,
      "backup_age_hours": 39
    }
  },
  "next_scheduled": {
    "database": "2025-08-09T02:00:00Z",
    "files": "2025-08-14T03:00:00Z"
  },
  "retention_policy": {
    "daily_backups": 7,
    "weekly_backups": 4,
    "monthly_backups": 6
  }
}
```

##### 3. Database Metrics
```bash
curl -H "X-API-Key: YOUR_API_KEY" \
  http://localhost/api/monitoring/database
```

### Authentication Methods

The API supports multiple authentication methods:

```bash
# Method 1: Bearer Token (Recommended)
curl -H "Authorization: Bearer YOUR_API_KEY" \
  http://localhost/api/monitoring/backup-status

# Method 2: X-API-Key Header
curl -H "X-API-Key: YOUR_API_KEY" \
  http://localhost/api/monitoring/database

# Method 3: Query Parameter (Not recommended)
curl "http://localhost/api/monitoring/detailed-health?api_key=YOUR_API_KEY"

# Method 4: Basic Auth
curl -u YOUR_API_KEY:anything \
  http://localhost/api/monitoring/backup-status
```

### Generate API Key

```bash
# Generate a secure API key
./vendor/bin/sail php artisan tinker --execute="echo bin2hex(random_bytes(32));"
```

---

## Automation

### Method 1: Direct Cron Job with Bash Script (Recommended for Independence)

For a completely autonomous backup system that doesn't rely on Laravel's scheduler, use the provided bash script with cron.

#### 1. Prepare the Backup Script

The project includes a backup script at `scripts/daily-backup.sh` with the following features:
- **Auto-detects project path** when run from the `scripts` folder
- **Logs stored in project**: `storage/logs/backup.log` (not in `/var/log`)
- Accepts optional `--project-path` argument for custom locations
- Supports both standard PHP and Laravel Sail execution
- Validates Laravel project structure
- Automatic log rotation when file exceeds 10MB
- Comprehensive logging and error handling

```bash
# View script usage
./scripts/daily-backup.sh --help

# Test with auto-detection (when script is in project/scripts/)
cd /path/to/mydatabase
./scripts/daily-backup.sh

# Test with auto-detection + Sail
./scripts/daily-backup.sh --use-sail

# Explicitly specify project path
./scripts/daily-backup.sh --project-path=/path/to/mydatabase

# Custom log file (if you don't want to use storage/logs/backup.log)
./scripts/daily-backup.sh --log-file=/custom/path/backup.log
```

#### 2. Setup for Production

```bash
# Copy script to system location
sudo cp scripts/daily-backup.sh /usr/local/bin/mydatabase-backup
sudo chmod +x /usr/local/bin/mydatabase-backup

# Create log file with proper permissions
sudo touch /var/log/mydatabase-backup.log
sudo chmod 666 /var/log/mydatabase-backup.log
```

#### 3. Configure Cron Job

```bash
# Edit crontab for the web server user
sudo crontab -u www-data -e

# Add ONE of these lines (choose your preferred schedule):

# Daily at 23:00 (11 PM) - Auto-detect if script is in project
0 23 * * * /var/www/mydatabase/scripts/daily-backup.sh

# Daily at 23:00 (11 PM) - With explicit path
0 23 * * * /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase

# Daily at 23:00 (11 PM) - Auto-detect + Laravel Sail
0 23 * * * /var/www/mydatabase/scripts/daily-backup.sh --use-sail

# With custom log file
0 23 * * * /var/www/mydatabase/scripts/daily-backup.sh --log-file=/var/log/custom-backup.log
```

#### 4. Alternative Cron Schedules

```bash
# Every day at 23:00 (11 PM)
0 23 * * * /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase

# Every day at 2:00 AM
0 2 * * * /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase

# Every Sunday at 23:00
0 23 * * 0 /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase

# Every 6 hours
0 */6 * * * /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase

# Monday to Friday at 23:00
0 23 * * 1-5 /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase

# First day of every month at 23:00
0 23 1 * * /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase

# Multiple backups with different types
0 2 * * * /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase  # Daily at 2 AM
0 3 * * 0 /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase  # Weekly on Sunday at 3 AM
0 4 1 * * /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase  # Monthly on 1st at 4 AM
```

#### 5. One-Line Cron Commands (Without Script)

If you prefer not to use the script:

```bash
# Standard PHP installation
0 23 * * * cd /var/www/mydatabase && /usr/bin/php artisan backup:all --type=daily >> /var/log/mydatabase-backup.log 2>&1

# Laravel Sail installation
0 23 * * * cd /var/www/mydatabase && ./vendor/bin/sail php artisan backup:all --type=daily >> /var/log/mydatabase-backup.log 2>&1

# With timeout to prevent hanging
0 23 * * * timeout 3600 bash -c 'cd /var/www/mydatabase && php artisan backup:all --type=daily' >> /var/log/mydatabase-backup.log 2>&1
```

#### 6. Monitoring and Verification

```bash
# Verify cron job is installed
sudo crontab -u www-data -l

# Check if cron service is running
sudo systemctl status cron    # Ubuntu/Debian
sudo systemctl status crond   # CentOS/RHEL

# Monitor backup execution in real-time
tail -f /var/log/mydatabase-backup.log

# Check system cron logs
grep CRON /var/log/syslog    # Ubuntu/Debian
grep CRON /var/log/cron      # CentOS/RHEL

# View last backup status in database
php artisan tinker
>>> App\Models\BackupLog::latest()->first()
```

#### 7. Email Notifications

Configure email notifications for backup results:

```bash
# Install mail utilities if needed
sudo apt-get install mailutils   # Ubuntu/Debian
sudo yum install mailx           # CentOS/RHEL

# Edit crontab
sudo crontab -u www-data -e

# Add email configuration at the top
MAILTO=admin@example.com
MAILFROM=backup@mydatabase.com

# Your backup job will now send results via email
0 23 * * * /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase
```

#### 8. Troubleshooting Cron Jobs

Common issues and solutions:

```bash
# Issue: Command not found
# Solution: Use absolute paths
0 23 * * * /usr/bin/php /var/www/mydatabase/artisan backup:all --type=daily

# Issue: Permission denied
# Solution: Check user permissions
sudo -u www-data php artisan backup:all --type=daily

# Issue: Environment variables not loaded
# Solution: Source the environment
0 23 * * * . /var/www/mydatabase/.env && php /var/www/mydatabase/artisan backup:all --type=daily

# Issue: Backup takes too long
# Solution: Add timeout
0 23 * * * timeout 7200 /usr/local/bin/mydatabase-backup --project-path=/var/www/mydatabase
```

### Method 2: Laravel Scheduler

Alternatively, use Laravel's built-in scheduler in `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Daily database backup at 2:00 AM
    $schedule->command('backup:database --type=daily')
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->onFailure(function () {
            Log::error('Scheduled database backup failed');
        });

    // Weekly application files backup on Sundays at 3:00 AM
    $schedule->command('backup:files --type=weekly')
        ->weeklyOn(0, '03:00')
        ->withoutOverlapping();

    // Daily uploads backup at 2:30 AM
    $schedule->command('backup:uploads --type=daily')
        ->dailyAt('02:30')
        ->withoutOverlapping();

    // Clean old backups daily at 4:00 AM
    $schedule->command('backup:clean')
        ->dailyAt('04:00');
}
```

### Running the Scheduler

For production, add this to your crontab:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

For development with Sail:
```bash
./vendor/bin/sail php artisan schedule:work
```

---

## Restoration

### Restore Database from Backup

#### For Unencrypted Backups:

1. **Download backup from S3** (if using S3):
```bash
# List available backups
./vendor/bin/sail php artisan tinker
>>> App\Models\BackupLog::where('type', 'database')->successful()->latest()->get()

# Note the filename and location
```

2. **Extract the backup**:
```bash
gunzip storage/app/backups/mydatabase_backup_database_2025-08-08_181210.sql.gz
```

3. **Restore to database**:
```bash
# Using Sail
./vendor/bin/sail mysql mydatabase < mydatabase_backup_database_2025-08-08_181210.sql

# Or directly with mysql
mysql -u root -p mydatabase < mydatabase_backup_database_2025-08-08_181210.sql
```

#### For Encrypted Backups:

1. **Download backup from S3** (if using S3)

2. **Decrypt the backup**:
```bash
# Decrypt (will prompt for password)
./vendor/bin/sail php artisan backup:decrypt \
  storage/app/backups/mydatabase_backup_database_2025-08-08_181210.sql.gz.enc

# This creates: mydatabase_backup_database_2025-08-08_181210.sql.gz
```

3. **Extract the decrypted backup**:
```bash
gunzip mydatabase_backup_database_2025-08-08_181210.sql.gz
```

4. **Restore to database**:
```bash
./vendor/bin/sail mysql mydatabase < mydatabase_backup_database_2025-08-08_181210.sql
```

### Restore Application Files

1. **Extract the backup**:
```bash
tar -xzf mydatabase_backup_files_2025-08-08_181215.tar.gz
```

2. **Copy files to appropriate locations**:
```bash
# Be careful - this will overwrite existing files
cp -r app/* /path-to-project/app/
cp -r config/* /path-to-project/config/
# ... etc
```

### Restore Uploads

```bash
# Extract to storage directory
tar -xzf mydatabase_backup_uploads_2025-08-08_181218.tar.gz -C storage/app/public/
```

---

## Troubleshooting

### Common Issues

#### 1. "Class League\Flysystem\AwsS3V3\PortableVisibilityConverter not found"
**Solution**: Install AWS S3 package
```bash
./vendor/bin/sail composer require league/flysystem-aws-s3-v3 "^3.0"
```

#### 2. "Failed to encrypt backup file"
**Check**:
- OpenSSL is installed in container
- Encryption password is set in `.env`
- Sufficient disk space for encrypted file

#### 3. "Failed to decrypt backup file"
**Check**:
- Correct password is being used
- File is actually encrypted (has `.enc` extension)
- File is not corrupted

#### 2. "Backup is disabled"
**Solution**: Set in `.env`
```env
BACKUP_ENABLED=true
```

#### 3. "mysqldump: command not found"
**Solution**: Install MySQL client in Docker container or use Sail's MySQL container
```bash
# Inside Sail container
apt-get update && apt-get install -y mysql-client
```

#### 4. S3 Upload Fails
**Check**:
- S3 credentials are correct
- Bucket exists and is accessible
- Network connectivity to S3 endpoint
- `BACKUP_DRIVER=s3` is set

#### 5. Permission Denied
**Solution**: Ensure backup directory has write permissions
```bash
chmod -R 755 storage/app/backups
chown -R www-data:www-data storage/app/backups
```

### View Backup Logs

```bash
# View all backups
./vendor/bin/sail php artisan tinker
>>> App\Models\BackupLog::all()

# View failed backups
>>> App\Models\BackupLog::where('status', 'failed')->get()

# View successful database backups
>>> App\Models\BackupLog::where('type', 'database')->successful()->get()

# Check last backup for each type
>>> App\Models\BackupLog::selectRaw('type, MAX(started_at) as last_backup')
    ->groupBy('type')->get()

# Check if backups are encrypted
>>> App\Models\BackupLog::latest()->first()->metadata['encrypted']

# View only encrypted backups
>>> App\Models\BackupLog::whereJsonContains('metadata->encrypted', true)->get()
```

### Check Monitoring Status

```bash
# Check overall system health
curl http://localhost/api/health

# Check detailed metrics (with API key)
curl -H "X-API-Key: YOUR_API_KEY" \
  http://localhost/api/monitoring/detailed-health | jq
```

### Manual Backup Verification

```bash
# Test database backup
./vendor/bin/sail php artisan backup:database

# Verify file was created
ls -lah storage/app/backups/

# For S3, check bucket
# Use AWS CLI or OVH console to verify upload
```

---

## Best Practices

1. **Test Restoration Regularly**: Don't wait for disaster to test your backups
2. **Monitor Backup Status**: Use the monitoring API to track backup health
3. **Secure Your API Key**: Never commit API keys to version control
4. **Use Different Retention Periods**: Keep daily backups short, monthly backups longer
5. **Store Offsite**: Use S3/OVH for disaster recovery
6. **Document Your Process**: Keep restoration procedures documented
7. **Automate Everything**: Use scheduler for consistent backups
8. **Monitor Storage Space**: Both local and S3 storage costs money
9. **Always Encrypt Backups**: Enable encryption for production environments
10. **Test in Staging**: Always test backup/restore in staging first

### Security Best Practices for Encryption

1. **Password Management**:
   - Use different passwords for different environments
   - Store passwords in a secure password manager or vault
   - Never commit passwords to version control
   - Rotate passwords periodically

2. **Backup Your Encryption Password**:
   - Keep multiple secure copies of your encryption password
   - Consider using a key management service (KMS)
   - Document password in secure company vault

3. **Test Decryption Regularly**:
   - Verify you can decrypt old backups
   - Test restoration process quarterly
   - Document the decryption process

4. **Compliance**:
   - Encryption helps meet GDPR, HIPAA, PCI-DSS requirements
   - Document encryption methods for audits
   - Keep encryption logs for compliance

---

## Support

For issues or questions:
- Check Laravel logs: `storage/logs/laravel.log`
- Check backup logs in database: `backup_logs` table
- Review monitoring metrics: `/api/monitoring/detailed-health`
- GitHub Issues: Report bugs or request features

---

*Last Updated: August 2025*
*Version: 1.0.0*