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

];
