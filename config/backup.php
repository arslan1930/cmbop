<?php

return [

    /*
    | Daily off-site backup (Google Drive). Shared hosting: no Redis.
    | See docs/BACKUPS.md.
    */

    'keep' => max(1, (int) env('BACKUP_KEEP', 30)),

    'prefix' => env('BACKUP_PREFIX', 'seolinkbuildings-backup'),

    'chunk_bytes' => max(256 * 1024, (int) env('BACKUP_UPLOAD_CHUNK', 8 * 256 * 1024)),

    /*
     | Extra/override media directories. Null = default public disk + public/uploads.
     | Tests set this to [] so the zip is only database.sql.gz.
     */
    'media_roots' => null,

    'google' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
        'token_url' => 'https://oauth2.googleapis.com/token',
        'upload_url' => 'https://www.googleapis.com/upload/drive/v3/files',
        'files_url' => 'https://www.googleapis.com/drive/v3/files',
    ],

];
