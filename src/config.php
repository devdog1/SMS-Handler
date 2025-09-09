<?php

// Default configuration settings.
// It's recommended to copy this to config.local.php and modify it there.
// The daemon will use config.local.php if it exists.

return [
    'debug' => true,

    'modem' => [
        'host' => '192.168.1.100', // Example IP for a modem on the local network
        'port' => 5000,
        'timeout' => 10,
    ],

    'spool' => [
        'outgoing' => '/var/spool/sms/',
        'failed' => '/var/spool/sms/failed/',
    ],

    'database' => [
        'host' => 'localhost',
        'user' => 'sms_user',
        'pass' => 'password', // IMPORTANT: Change this password
        'name' => 'sms_daemon_db',
    ],

    'daemon' => [
        'send_interval' => 1, // sleep in seconds after sending one message
        'loop_interval' => 5, // sleep in seconds at the end of the main loop
        'send_batch_size' => 10, // max messages to send per cycle
    ],

    'plugins' => [
        'block_numbers' => [
            // Add 10-digit numbers here to block them from sending or receiving.
            // e.g., '5551234567',
        ],
        'allowed_area_codes' => [
            // If this list is not empty, only messages to/from these area codes will be processed.
            // e.g., '204', '431', '584',
        ],
    ],
];
