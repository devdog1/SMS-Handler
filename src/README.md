# SMS Daemon

A modular and extensible PHP daemon for sending and receiving SMS messages via a GSM modem.

## Overview

This project is a long-running PHP script that connects to a GSM modem over a TCP socket to perform two main tasks:
1.  **Send outgoing messages**: It monitors a spool directory for files and sends them as SMS messages.
2.  **Receive incoming messages**: It polls the modem for new messages and processes them using a plugin-based system.

The system is designed to be robust and easy to extend.

## Architecture

The application is structured into two main directories within `src/`:

-   `Lib/`: Contains the core library classes that provide the main functionalities.
    -   `Socket.php`: A low-level wrapper for TCP socket communication.
    -   `Modem.php`: An abstraction layer for communicating with the GSM modem using AT commands.
    -   `PluginManager.php`: Discovers, loads, and executes plugins.
-   `Plugins/`: Contains the business logic for handling various types of incoming SMS messages. Each plugin is a self-contained class that handles a specific command or message type.

## Configuration

Configuration is handled by `config.php`. For local environments, it is highly recommended to create a `config.local.php` file to override the default settings. The daemon will automatically load this file if it exists.

1.  Copy the default config file:
    ```bash
    cp src/config.php src/config.local.php
    ```
2.  Edit `src/config.local.php` with your specific settings for the modem, spool directories, and database.

```php
// src/config.local.php
return [
    'modem' => [
        'host' => '192.168.1.100', // Your modem's IP
        'port' => 5000,
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
            '5557654321',
        ],
        'allowed_area_codes' => [
            '204',
            '431',
        ],
    ],
];
```

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

These steps ensure that the daemon runs as a non-privileged user (`sms-daemon`) and can safely interact with files created by another user (e.g., your web server user, `www-data`).

1.  **Create a dedicated user and group for the daemon:**
    ```bash
    sudo groupadd --system sms-daemon
    sudo useradd --system --no-create-home --gid sms-daemon sms-daemon
    ```

2.  **Create a shared group for the spool directory:**
    This group will be shared by the `sms-daemon` user and the user that creates the message files (e.g., `www-data`).
    ```bash
    sudo groupadd sms-spool
    ```

3.  **Add users to the shared group:**
    ```bash
    sudo usermod -a -G sms-spool sms-daemon
    sudo usermod -a -G sms-spool www-data  # Replace www-data with your web user if different
    ```

4.  **Set permissions for the spool directory:**
    These commands give ownership of the spool directory to the shared group and ensure that new files created within it inherit the correct group permissions.
    ```bash
    sudo chown -R root:sms-spool /var/spool/sms
    sudo chmod -R 775 /var/spool/sms
    sudo chmod g+s /var/spool/sms
    ```
    **Note:** For the group permissions to work correctly, the application that creates the message files must have a `umask` of `002`. This ensures files are created with group-write permissions (`664`).

### 3. Running as a Service (`systemd`)

1.  **Copy the service file:**
    A sample service file is provided. Copy it to the systemd directory.
    ```bash
    sudo cp /opt/sms-daemon/deployment/sms-daemon.service /etc/systemd/system/sms-daemon.service
    ```

2.  **Reload the systemd daemon:**
    ```bash
    sudo systemctl daemon-reload
    ```

3.  **Enable the service to start on boot:**
    ```bash
    sudo systemctl enable sms-daemon.service
    ```

4.  **Start the service:**
    ```bash
    sudo systemctl start sms-daemon.service
    ```

5.  **Check the service status:**
    You can check the status and view recent logs with this command:
    ```bash
    sudo systemctl status sms-daemon.service
    ```

## Included Plugins

The daemon comes with several pre-built plugins. Incoming messages are checked against each plugin in alphabetical order of the plugin's filename.

| Plugin                  | Trigger Keyword(s) | Description                                                                                             |
| ----------------------- | ------------------ | ------------------------------------------------------------------------------------------------------- |
| `AreaCodeWhitelistPlugin` | (n/a)              | If configured, only allows messages from/to area codes in the `plugins.allowed_area_codes` list.      |
| `BlockNumberPlugin`     | (n/a)              | Blocks incoming/outgoing messages from/to numbers in the `plugins.block_numbers` config list.           |
| `ZabbixAckPlugin`       | `120 E:54321`      | Acknowledges a Zabbix event. The first number is the duration in minutes.                               |
| `BulkAckPlugin`         | `ok`, `go`, `fuck` | Performs a bulk acknowledgement of all recent events for the sender.                                    |
| `HistoryPlugin`         | `history`          | Responds with the last 5 messages that were sent to the requesting user.                                |
| `SignaturePlugin`       | (n/a)              | Appends a signature to all outgoing messages.                                                           |
| `ZzzDefaultReplyPlugin` | (any other text)   | A fallback that replies with a "bad message" response if no other plugin handles the SMS.               |

## Extending the Daemon (Creating a New Plugin)

The plugin system makes it easy to add new functionality. To create a new plugin:

1.  Create a new PHP file in the `src/Plugins/` directory (e.g., `MyNewPlugin.php`).
2.  Define a class that extends `BasePlugin`.
3.  Implement the `handleIncoming(array $message): ?string` and/or `handleOutgoing(array $messageData): ?array` methods.
    -   For incoming messages, check if the message is one your plugin should handle. If so, perform your logic and return a response string. Otherwise, return `null`.
    -   For outgoing messages, perform your logic and return the modified message data, or return `null` to cancel sending.

### Example Plugin

Here is a simple example for a "ping" plugin.

**`src/Plugins/PingPlugin.php`**:
```php
<?php
namespace SmsDaemon\Plugins;

class PingPlugin extends BasePlugin
{
    public function handleIncoming(array $message): ?string
    {
        if (strtolower(trim($message['text'])) === 'ping') {
            $this->log("Handling ping request from {$message['sender']}.");
            return 'pong';
        }
        return null; // Not a ping message, let other plugins handle it.
    }
}
```
The daemon will automatically discover and use this new plugin on its next run.
