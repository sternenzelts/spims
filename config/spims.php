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

];
