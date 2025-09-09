<?php

// The daemon should run indefinitely and not time out.
set_time_limit(0);

// Use a consistent root directory path.
define('ROOT_DIR', __DIR__);

/**
 * A simple PSR-4 autoloader for the SmsDaemon namespace.
 * It maps the SmsDaemon\ namespace to the current directory (src/).
 */
spl_autoload_register(function ($class) {
    $prefix = 'SmsDaemon\\';
    $base_dir = ROOT_DIR . '/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return; // Not a class from our namespace
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use SmsDaemon\Lib\Modem;
use SmsDaemon\Lib\PluginManager;

// --- Configuration Loading ---
$config_file = ROOT_DIR . '/config.php';
$local_config_file = ROOT_DIR . '/config.local.php';

if (!file_exists($config_file)) {
    die("FATAL: Main configuration file not found at {$config_file}\n");
}
$config = require $config_file;

// Allow overriding with a local config file (which is not version controlled)
if (file_exists($local_config_file)) {
    $local_config = require $local_config_file;
    $config = array_replace_recursive($config, $local_config);
}

$debug = $config['debug'] ?? false;

function log_message(string $message) {
    global $debug;
    if ($debug) {
        // Using a consistent prefix for all daemon logs.
        error_log("SMS-DAEMON-MAIN: " . $message);
    }
}

log_message("Daemon starting up. Debug mode is " . ($debug ? 'ON' : 'OFF'));

// --- Initialization ---
$modem = new Modem($config['modem'], $debug);
$pluginManager = new PluginManager(ROOT_DIR . '/Plugins', $config['database'], $debug);

$outgoingDir = $config['spool']['outgoing'];
$failedDir = $config['spool']['failed'];
if (!is_dir($failedDir)) {
    @mkdir($failedDir, 0755, true);
}

// --- Main Loop ---
log_message("Entering main processing loop.");
while (true) {
    // 1. Process outgoing message queue
    log_message("Checking for outgoing messages in {$outgoingDir}");
    $files = @scandir($outgoingDir) ?: [];
    $sentCount = 0;

    if ($modem->connect()) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filePath = $outgoingDir . $file;
            if (!is_file($filePath)) continue;

            log_message("Processing file: {$file}");
            $message = file_get_contents($filePath);
            $number = substr($file, -10); // Assumes last 10 digits are the number

            if (strlen($number) !== 10) {
                 log_message("Invalid phone number format in filename: {$file}. Moving to failed directory.");
                 @rename($filePath, $failedDir . $file);
                 continue;
            }

            $outgoingMessageData = [
                'number' => $number,
                'message' => $message,
            ];

            // Pass the outgoing message through the plugin system
            $processedMessageData = $pluginManager->dispatchOutgoing($outgoingMessageData);

            if ($processedMessageData === null) {
                log_message("Outgoing message to {$number} was cancelled by a plugin. Moving to failed directory.");
                @rename($filePath, $failedDir . $file);
            } else {
                log_message("Sending message to {$processedMessageData['number']} after plugin processing.");
                if ($modem->sendMessage($processedMessageData['number'], $processedMessageData['message'])) {
                    log_message("Successfully sent message from file {$file}. Deleting file.");
                    @unlink($filePath);
                } else {
                    log_message("Failed to send message from file {$file}. Moving to failed directory.");
                    @rename($filePath, $failedDir . $file);
                }
            }

            $sentCount++;
            if ($sentCount >= $config['daemon']['send_batch_size']) {
                log_message("Sent batch of {$sentCount}. Pausing sending to allow for receiving.");
                break;
            }

            log_message("Sleeping for {$config['daemon']['send_interval']} second(s).");
            sleep($config['daemon']['send_interval']);
        }
        $modem->disconnect();
    } else {
        log_message("Could not connect to modem to send. Will retry next loop.");
    }
    log_message("Finished processing outgoing messages for this cycle.");

    // 2. Process incoming messages
    log_message("Checking for incoming messages.");
    if ($modem->connect()) {
        $incomingMessages = $modem->listMessages();
        if (!empty($incomingMessages)) {
            log_message("Found " . count($incomingMessages) . " new message(s).");
            foreach ($incomingMessages as $msg) {
                log_message("Processing message ID {$msg['id']} from {$msg['sender']}.");
                $response = $pluginManager->dispatchIncoming($msg);

                if ($response) {
                    log_message("Plugin provided a response. Sending reply to {$msg['sender']}.");
                    $modem->sendMessage($msg['sender'], $response);
                }

                log_message("Deleting processed message ID {$msg['id']} from modem.");
                $modem->deleteMessage($msg['id']);
            }
        } else {
            log_message("No new messages found.");
        }
        $modem->disconnect();
    } else {
        log_message("Could not connect to modem to receive. Will retry next loop.");
    }

    log_message("Main loop cycle finished. Sleeping for {$config['daemon']['loop_interval']} seconds.");
    sleep($config['daemon']['loop_interval']);
}
