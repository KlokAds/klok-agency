<?php

return [

    /*
    | The in-app timer (agency:keep-alive) for a host with no cron: visits start it, it ticks every
    | minute and wakes Klok Pilot. Off where a real cron runs `schedule:run`.
    */

    'keep_alive' => (bool) env('KEEP_ALIVE', false),

    /*
    | The PHP command line the timer is started with (web requests run under another PHP binary).
    */

    'php_cli' => env('PHP_CLI_PATH', 'php'),

    /*
    | The Android app, and the SHA-256 of the key it is signed with (comma-separated for several):
    | served in /.well-known/assetlinks.json so this site's links open in the app.
    */

    'android_package' => env('ANDROID_PACKAGE', 'com.klokads.finance'),

    'android_sha256' => env('ANDROID_SHA256', 'A4:A3:62:42:AB:96:4A:1F:7C:2A:E9:92:EC:A8:FD:BB:71:BA:05:5F:C0:FF:D2:7C:F0:9C:8A:28:AB:D4:C1:EA'),

];
