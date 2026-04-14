# SMS Daemon

A modular and extensible PHP daemon for sending and receiving SMS messages via multiple backends.

## Overview

This project is a long-running PHP script that supports multiple backends (GSM modem or Android SMS Gateway) to perform two main tasks:
1.  **Send outgoing messages**: It monitors a spool directory for files and sends them as SMS messages.
2.  **Receive incoming messages**: It polls the backend or listens for webhooks for new messages and processes them using a plugin-based system.

The system is designed to be robust and easy to extend.

## Architecture

The application is structured into two main directories within `src/`:

-   `Lib/`: Contains the core library classes that provide the main functionalities.
    -   `SmsHandlerInterface.php`: Defines the contract for SMS backends.
    -   `Modem.php`: Communicates with a GSM modem using AT commands.
    -   `AndroidSmsGatewayHandler.php`: Integrates with capcom6's Android SMS Gateway.
    -   `PluginManager.php`: Discovers, loads, and executes plugins.
-   `Web/`: Web-facing components (requires Apache/Nginx).
    -   `callback.php`: Receives incoming messages via webhooks from Android SMS Gateway.
    -   `status.php`: A real-time monitoring dashboard.
-   `Plugins/`: Contains the business logic for handling various types of incoming SMS messages. Each plugin is a self-contained class that handles a specific command or message type.

## Configuration

Configuration is handled by `src/config.php`. For local environments, it is highly recommended to create a `src/config.local.php` file to override the default settings. The daemon will automatically load this file if it exists.

1.  Copy the default config file:
    ```bash
    cp src/config.php src/config.local.php
    ```
2.  Edit `src/config.local.php` with your specific settings for the modem, spool directories, and database.

```php
// src/config.local.php
return [
    'backend' => 'modem', // 'modem' or 'android_sms_gateway'

    'modem' => [
        'host' => '192.168.1.100', // Your modem's IP
        'port' => 5000,
    ],

    'android_sms_gateway' => [
        'baseUrl' => 'https://api.sms-gate.app/3rdparty/v1',
        'login' => 'your_login',
        'password' => 'your_password',
        'webhook' => [
            'enabled' => true,
            'url' => 'https://your-public-url.com/callback.php',
            'secret' => 'your_hmac_secret',
        ],
    ],
    'database' => [
        'host' => 'localhost',
        'user' => 'your_user',
        'pass' => 'your_password',
        'name' => 'sms_daemon_db',
    ],
    'plugins' => [
        'block_numbers' => [
            '5551234567',
        ],
        'allowed_area_codes' => [
            '204',
            '431',
        ],
        'flood_control' => [
            'threshold' => 50,
            'summary_number' => '5551112222', // Admin number for flood alerts
            'lockout_time' => 300, // Seconds
        ],
        'oncall_groups' => [
            10 => 20, // Map On-Call group (ID 10) to Pool group (ID 20)
        ],
        'global_pause' => [
            'authorized_group_id' => 12, // Zabbix User Group ID allowed to pause all
        ],
        'spool_clear' => [
            'authorized_group_id' => 12, // Zabbix User Group ID allowed to clear spool
        ],
    ],
];
```

## Database Initialization

Several plugins and core features (Global Pause, SMS Logger, Dashboard, etc.) require database tables to function. To initialize the database schema, run the following script:

```bash
php init_db.php
```

## Monitoring Dashboard

A web-based dashboard is available at `src/Web/status.php`. It provides:
-   Real-time daemon health status (active/inactive).
-   Spool statistics (Outgoing, Incoming, Failed).
-   Recent database logs for both incoming and outgoing messages.
-   Last 20 lines of the system log.

## Webhook Configuration (Android SMS Gateway)

When using the `android_sms_gateway` backend, the daemon will automatically synchronize webhook settings on startup.
1.  Set the `url` in `config.php` to your public `callback.php` address.
2.  The URL **must** use `https://` (per gateway requirements).
3.  The daemon will clear old webhooks and register for SMS, MMS, and Data events.

## Special Configuration for Plugins

### On-Call Manager
The `OnCallManagerPlugin` requires a mapping in the `oncall_groups` config key. This mapping pairs an **On-Call Group ID** (the group that receives alerts) with a **Pool Group ID** (the group containing all users eligible for that rotation). A user can only switch on-call status for a rotation if they are already a member of its corresponding pool group.

### Flood Control
The `AaaFloodControlPlugin` monitors the outgoing spool. If a single recipient receives more than the `threshold` number of messages, the plugin will:
1.  Enter a lockout period for that recipient.
2.  Clear the pending messages for that recipient from the spool.
3.  Send a summary alert to both the recipient and the configured `summary_number` (administrator).

## Production Setup and Usage

For a production environment, it is highly recommended to run the daemon as its own dedicated user and manage it with a process manager like `systemd`.

### 1. Installation

1.  **Copy application files** to a standard location, such as `/opt`.
    ```bash
    sudo cp -r . /opt/sms-daemon
    ```

2.  **Set ownership** of the application directory to the dedicated user.
    ```bash
    sudo chown -R sms-daemon:sms-daemon /opt/sms-daemon
    ```

### 2. User and Group Setup

1.  **Create a dedicated user and group for the daemon:**
    ```bash
    sudo groupadd --system sms-daemon
    sudo useradd --system --no-create-home --gid sms-daemon sms-daemon
    ```

2.  **Create a shared group for the spool directory:**
    ```bash
    sudo groupadd sms-spool
    ```

3.  **Add users to the shared group:**
    ```bash
    sudo usermod -a -G sms-spool sms-daemon
    sudo usermod -a -G sms-spool www-data
    ```

4.  **Set permissions for the spool directory:**
    ```bash
    sudo chown -R root:sms-spool /var/spool/sms
    sudo chmod -R 775 /var/spool/sms
    sudo chmod g+s /var/spool/sms
    ```

### 3. Running as a Service (`systemd`)

1.  **Copy the service file:**
    ```bash
    sudo cp /opt/sms-daemon/deployment/sms-daemon.service /etc/systemd/system/sms-daemon.service
    ```

2.  **Reload, Enable, and Start:**
    ```bash
    sudo systemctl daemon-reload
    sudo systemctl enable sms-daemon.service
    sudo systemctl start sms-daemon.service
    ```

## Included Plugins

Plugins are executed in alphabetical order.

| Plugin | Trigger Keyword(s) | Description |
| :--- | :--- | :--- |
| `AaaFloodControlPlugin` | (n/a) | Monitors outgoing spool for flooding and suppresses messages if threshold reached. |
| `AaaZabbixAuthPlugin` | (n/a) | Authenticates incoming messages by checking if the sender exists in Zabbix user media. |
| `AckCheckPlugin` | (n/a) | Checks if an alert has an active acknowledgement before sending. |
| `AreaCodeWhitelistPlugin` | (n/a) | Restricts messages to/from configured area codes. |
| `BbbGlobalPausePlugin` | `pause <min>`, `unpause`, `pause status` | Temporarily pauses all outgoing SMS messages for authorized users. |
| `BlockNumberPlugin` | (n/a) | Blocks messages from/to specific numbers. |
| `BulkAckPlugin` | `ok`, `fuck` | Bulk acknowledges all recent events for the sender. |
| `HelpPlugin` | `help` | Lists available commands. |
| `HistoryPlugin` | `history` | Responds with the last 5 messages sent to the user. |
| `NumberPausePlugin` | `stop <min>`, `go` | Allows users to pause/resume alerts for their own number. |
| `OnCallManagerPlugin` | `oncall`, `oncall <group>` | Lists on-call members or switches on-call status for a group. |
| `SignaturePlugin` | (n/a) | Appends a signature to outgoing messages. |
| `SmsLoggerPlugin` | (n/a) | Logs sent, withheld, and failed messages to the database. |
| `SpoolClearPlugin` | `clear spool` | Clears the outgoing spool directory (authorized users only). |
| `ZabbixAckPlugin` | `<min> E:<id>` | Acknowledges a specific Zabbix event. |
| `ZabbixLastEventAckPlugin`| `ack <min>` | Acknowledges the last event sent to the sender. |
| `ZabbixTriggerDisablePlugin`| `disable E:<id>` | Disables a Zabbix trigger. |
| `ZzzDefaultReplyPlugin` | (any other text) | Fallback reply for unknown commands. |

## Extending the Daemon

To create a new plugin, add a PHP file to `src/Plugins/` extending `BasePlugin` and implementing `handleIncoming` or `handleOutgoing`.
