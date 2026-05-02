<?php
// config/storage.php
//
// Where uploaded files live.
//
// driver = 'local' | 's3'
//   local — files go to STORAGE_PATH/uploads/... on disk. Served through
//           UploadsController@serve at /uploads/{folder}/{file}. No extra
//           service required.
//   s3    — files go to a bucket (MinIO in dev, AWS S3 or compatible in
//           production). Served either by direct public URL (when the bucket
//           is public) or — later — pre-signed URLs if you tighten access.
//
// The 'public_url_base' lets you point at a CDN in production without touching
// application code — e.g. S3_PUBLIC_URL=https://cdn.mysite.com would make all
// stored files render from CloudFront without any refactor.

return [
    'driver' => $_ENV['STORAGE_DRIVER'] ?? 'local',

    // Local-disk settings (driver='local')
    'local' => [
        'root_path' => ($_ENV['STORAGE_PATH'] ?? BASE_PATH . '/storage') . '/uploads',
        'public_url_base' => rtrim((string)($_ENV['APP_URL'] ?? ''), '/') . '/uploads',
    ],

    // S3 / MinIO settings (driver='s3')
    's3' => [
        'bucket'          => $_ENV['S3_BUCKET']          ?? 'claudephpframework-dev',
        'region'          => $_ENV['S3_REGION']          ?? 'us-east-1',
        'endpoint'        => $_ENV['S3_ENDPOINT']        ?? '',        // empty = real AWS
        'access_key'      => $_ENV['S3_ACCESS_KEY']      ?? '',
        'secret_key'      => $_ENV['S3_SECRET_KEY']      ?? '',
        'use_path_style'  => ($_ENV['S3_USE_PATH_STYLE'] ?? '0') === '1',
        // Public URL base (without trailing slash). MinIO with public bucket:
        //   http://localhost:9000/claudephpframework-dev
        // Real AWS S3 with public bucket:
        //   https://claudephpframework-prod.s3.us-east-1.amazonaws.com
        // Behind CloudFront / other CDN:
        //   https://cdn.example.com
        'public_url_base' => rtrim((string)($_ENV['S3_PUBLIC_URL'] ?? ''), '/'),
    ],
];
