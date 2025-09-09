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
        'name' => 'zabbix',
    ],
];
```

## Usage

To run the daemon, execute the main script from your terminal:

```bash
php src/sms_daemon.php
```

It's recommended to run this as a background process using a process manager like `supervisor` or `systemd` to ensure it runs continuously and is restarted on failure.

## Included Plugins

The daemon comes with several pre-built plugins. Incoming messages are checked against each plugin in alphabetical order of the plugin's filename.

| Plugin                  | Trigger Keyword(s) | Description                                                                                             |
| ----------------------- | ------------------ | ------------------------------------------------------------------------------------------------------- |
| `ZabbixAckPlugin`       | `120 E:54321`      | Acknowledges a Zabbix event. The first number is the duration in minutes.                               |
| `BulkAckPlugin`         | `ok`, `go`, `fuck` | Performs a bulk acknowledgement of all recent events for the sender.                                    |
| `HistoryPlugin`         | `history`          | Responds with the last 5 messages that were sent to the requesting user.                                |
| `ZzzDefaultReplyPlugin` | (any other text)   | A fallback that replies with a "bad message" response if no other plugin handles the SMS.               |

## Extending the Daemon (Creating a New Plugin)

The plugin system makes it easy to add new functionality. To create a new plugin:

1.  Create a new PHP file in the `src/Plugins/` directory (e.g., `MyNewPlugin.php`).
2.  Define a class that extends `BasePlugin`.
3.  Implement the `handle(array $message): ?string` method.
    -   Check if the incoming message is one your plugin should handle.
    -   If it is, perform your logic and return a string that will be sent back as an SMS response.
    -   If the message is not for your plugin, return `null`.

### Example Plugin

Here is a simple example for a "ping" plugin.

**`src/Plugins/PingPlugin.php`**:
```php
<?php
namespace SmsDaemon\Plugins;

class PingPlugin extends BasePlugin
{
    public function handle(array $message): ?string
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
