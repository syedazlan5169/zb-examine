<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business Timezone
    |--------------------------------------------------------------------------
    |
    | Malaysian business-date rules (e.g. submission-number date/sequence
    | bucketing) use this timezone. This is intentionally separate from
    | config('app.timezone'), which stays UTC for general application
    | timestamps such as created_at/updated_at.
    |
    */

    'business_timezone' => env('BUSINESS_TIMEZONE', 'Asia/Kuala_Lumpur'),

    /*
    |--------------------------------------------------------------------------
    | Photo Upload Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk name used for pre-Examination photo uploads. Reading
    | the disk name from config (rather than hard-coding it) is what makes
    | swapping to a DigitalOcean Spaces disk later a one-line change.
    |
    */

    'photo_upload_mode' => env('PHOTO_UPLOAD_MODE', 'proxy'),

    'photo_upload_disk' => env('PHOTO_UPLOAD_DISK', 'photo_uploads'),

    'photo_upload_direct_disk' => env('PHOTO_UPLOAD_DIRECT_DISK', 'photo_uploads_spaces'),

    'photo_upload_presign_ttl_seconds' => (int) env('PHOTO_UPLOAD_PRESIGN_TTL', 300),

    'photo_preview_presign_ttl_seconds' => env('PHOTO_PREVIEW_PRESIGN_TTL', 120),

    'examination_photo_max_dimension' => max(1, min(10000, (int) env('EXAMINATION_PHOTO_MAX_DIMENSION', 1600))),

    'examination_photo_quality' => max(1, min(100, (int) env('EXAMINATION_PHOTO_QUALITY', 72))),

    /*
    |--------------------------------------------------------------------------
    | Photo Upload Cleanup Configuration
    |--------------------------------------------------------------------------
    |
    | Settling window: duration (seconds) before an authorized-for-deletion
    | intent becomes eligible for physical storage deletion. Allows in-flight
    | upload requests to complete before authoritative object removal,
    | preventing late-publication race conditions.
    |
    | Session batch: maximum expired sessions to stage per cleanup run.
    | Queue batch: maximum queued objects to process per cleanup run.
    |
    */

    'photo_cleanup_settle_seconds' => (int) env('PHOTO_CLEANUP_SETTLE_SECONDS', 3600),

    'photo_cleanup_session_limit' => (int) env('PHOTO_CLEANUP_SESSION_LIMIT', 100),

    'photo_cleanup_queue_limit' => (int) env('PHOTO_CLEANUP_QUEUE_LIMIT', 500),

];
