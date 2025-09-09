<?php

// Default configuration settings.
// It's recommended to copy this to config.local.php and modify it there.
// The daemon will use config.local.php if it exists.

return [
    'debug' => true,

    'modem' => [
        'host' => '64.93.111.9',
        'port' => 5000,
        'timeout' => 10,
    ],

    'spool' => [
        'outgoing' => '/var/spool/sms/',
        'failed' => '/var/spool/sms/failed/',
    ],

    'database' => [
        'host' => 'localhost',
        'user' => 'zabbix',
        'pass' => 'password', // IMPORTANT: Change this password
        'name' => 'zabbix',
    ],

    'daemon' => [
        'send_interval' => 1, // sleep in seconds after sending one message
        'loop_interval' => 5, // sleep in seconds at the end of the main loop
        'send_batch_size' => 10, // max messages to send per cycle
    ],

    'plugins' => [
        'block_numbers' => [
            // Add numbers here to block them from sending or receiving.
            // '5551234567',
        ],
    ],
];
