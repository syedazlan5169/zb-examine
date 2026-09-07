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

];
