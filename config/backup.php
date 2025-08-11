<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Configuration
    |--------------------------------------------------------------------------
    |
    | This file configures the backup system for the application.
    |
    */

    'enabled' => env('BACKUP_ENABLED', true),
    
    'driver' => env('BACKUP_DRIVER', 'local'),
    
    /*
    |--------------------------------------------------------------------------
    | Local Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for local backup storage.
    |
    */
    'local' => [
        'path' => env('BACKUP_LOCAL_PATH', storage_path('app/backups')),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    |
    | How many days to keep backups of each frequency type.
    |
    */
    'retention' => [
        'daily' => env('BACKUP_RETENTION_DAILY', 7),
        'weekly' => env('BACKUP_RETENTION_WEEKLY', 4),
        'monthly' => env('BACKUP_RETENTION_MONTHLY', 6),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | S3/OVH Object Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for S3-compatible storage backends.
    |
    */
    's3' => [
        'endpoint' => env('S3_BACKUP_ENDPOINT'),
        'region' => env('S3_BACKUP_REGION', 'us-east-1'),
        'key' => env('S3_BACKUP_KEY'),
        'secret' => env('S3_BACKUP_SECRET'),
        'bucket' => env('S3_BACKUP_BUCKET'),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Kopia Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Kopia backup tool.
    |
    */
    'kopia' => [
        'enabled' => env('KOPIA_ENABLED', false),
        'repository' => env('KOPIA_REPOSITORY'),
        'password' => env('KOPIA_PASSWORD'),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Encryption Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for backup encryption using OpenSSL.
    |
    */
    'encryption' => [
        'enabled' => env('BACKUP_ENCRYPTION_ENABLED', false),
        'password' => env('BACKUP_ENCRYPTION_PASSWORD'),
        'cipher' => 'aes-256-cbc', // Strong encryption cipher
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for monitoring endpoints.
    |
    */
    'monitoring' => [
        'enabled' => env('MONITORING_ENABLED', true),
        'api_key' => env('MONITORING_API_KEY'),
        'allowed_ips' => env('MONITORING_ALLOWED_IPS') ? explode(',', env('MONITORING_ALLOWED_IPS')) : [],
    ],
];