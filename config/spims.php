<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Badge UID Pepper
    |--------------------------------------------------------------------------
    |
    | Badge UIDs are stored as an HMAC-SHA256 keyed with this pepper (design
    | §8.2). Generate one per install with:
    |   php -r "echo bin2hex(random_bytes(32));"
    | Never change it on a live install: every enrolled badge stops matching.
    |
    */

    'badge_pepper' => env('BADGE_PEPPER'),

    /*
    |--------------------------------------------------------------------------
    | Stock Lock Timeout
    |--------------------------------------------------------------------------
    |
    | How long a posting waits for another station's stock row locks before
    | failing with StockBusy. Postgres interval syntax: 5s or 500ms.
    |
    */

    'lock_timeout' => env('SPIMS_LOCK_TIMEOUT', '5s'),

];
