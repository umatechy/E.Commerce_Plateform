<?php

// Laravel merges these disks with its default ones (local, public, s3);
// only the disks below are added here.
return [
    'disks' => [
        // Off-server backups (A9, docs/deployment/production.md): an
        // S3-compatible bucket, private, used only when BACKUP_DISK=backups_s3.
        // Its own settings, so the backup credentials are not shared with any
        // other storage. Errors are thrown, not swallowed: a backup that could
        // not be written must fail visibly (BackupService marks it failed).
        'backups_s3' => [
            'driver' => 's3',
            'key' => env('BACKUP_S3_ACCESS_KEY'),
            'secret' => env('BACKUP_S3_SECRET_KEY'),
            'region' => env('BACKUP_S3_REGION', 'eu-central'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('BACKUP_S3_PATH_STYLE', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],
    ],
];
