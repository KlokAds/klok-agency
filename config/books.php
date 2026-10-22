<?php

return [
    // Settings → App update: which GitHub branch the live server follows.
    'update_branch' => env('BOOKS_UPDATE_BRANCH', 'main'),

    // Command-line php matching the web one (Hostinger: /opt/alt/php85/usr/bin/php).
    'php_cli' => env('BOOKS_PHP_CLI'),

    // Path to composer when it is not on the usual paths.
    'composer' => env('BOOKS_COMPOSER'),
];
