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

    'photo_upload_disk' => env('PHOTO_UPLOAD_DISK', 'photo_uploads'),

];
