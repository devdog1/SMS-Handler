<?php

// Default configuration settings.
// It's recommended to copy this to config.local.php and modify it there.
// The daemon will use config.local.php if it exists.

return [
    'debug' => true,

    'backend' => 'modem', // 'modem' or 'android_sms_gateway'

    'modem' => [
        'host' => '192.168.1.100', // Example IP for a modem on the local network
        'port' => 5000,
        'timeout' => 10,
    ],

    'android_sms_gateway' => [
        'baseUrl' => 'https://api.sms-gate.app/3rdparty/v1',
        'login' => '',
        'password' => '',
        'deviceId' => '',
        'webhook' => [
            'enabled' => true,
            // NOTE: The Android SMS Gateway requires the URL to start with https://
            // unless using http://127.0.0.1 for local testing.
            'url' => 'https://sms-daemon.example.com/callback.php',
            'secret' => '', // Signing key from app
            'debug_dump' => false,
            'debug_dump_file' => sys_get_temp_dir() . '/sms_webhook_debug.log',
        ],
    ],

    'spool' => [
        'outgoing' => '/var/spool/sms/',
        'failed' => '/var/spool/sms/failed/',
        'incoming' => '/var/spool/sms/incoming/',
    ],

    'daemon' => [
        'send_interval' => 1, // sleep in seconds after sending one message
        'loop_interval' => 5, // sleep in seconds at the end of the main loop
        'send_batch_size' => 10, // max messages to send per cycle
        'heartbeat_file' => sys_get_temp_dir() . '/sms_daemon_heartbeat.json',
        'log_file' => sys_get_temp_dir() . '/sms_daemon.log',
    ],

    'database' => [
        'host' => 'localhost',
        'user' => 'sms_user',
        'pass' => 'password', // IMPORTANT: Change this password
        'name' => 'sms_daemon_db',
    ],


    'zabbix' => [
        'url' => 'http://zabbix/api_jsonrpc.php',
        'user' => 'Admin',
        'password' => 'zabbix',
        'cache_duration' => 3600, // 1 hour
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
        'flood_control' => [
            'threshold' => 50,
            'summary_number' => '', // Set a 10-digit number to receive alerts
        ],
        'global_pause' => [
            'authorized_group_id' => null, // Set to Zabbix User Group ID for authorization
        ],
        'spool_clear' => [
            'authorized_group_id' => null, // Set to Zabbix User Group ID for authorization
        ],
    ],
];
