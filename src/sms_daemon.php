<?php

// The daemon should run indefinitely and not time out.
set_time_limit(0);

// Use a consistent root directory path.
define('ROOT_DIR', __DIR__);

// Load polyfills for older PHP versions (e.g., 7.4.3)
require_once ROOT_DIR . '/Lib/Polyfills.php';

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
use SmsDaemon\Lib\AndroidSmsGatewayHandler;
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
    global $debug, $config;
    if ($debug) {
        $logEntry = "[" . date('Y-m-d H:i:s') . "] SMS-DAEMON-MAIN: " . $message . PHP_EOL;
        // Using a consistent prefix for all daemon logs.
        error_log("SMS-DAEMON-MAIN: " . $message);

        // Also log to the specific log file if configured
        if (!empty($config['daemon']['log_file'])) {
            @file_put_contents($config['daemon']['log_file'], $logEntry, FILE_APPEND);
        }
    }
}

function update_heartbeat(array $status) {
    global $config;
    if (!empty($config['daemon']['heartbeat_file'])) {
        $data = [
            'timestamp' => time(),
            'status' => $status
        ];
        @file_put_contents($config['daemon']['heartbeat_file'], json_encode($data));
    }
}

log_message("Daemon starting up. Debug mode is " . ($debug ? 'ON' : 'OFF'));

// --- Initialization ---
$backendType = $config['backend'] ?? 'modem';
$incomingDir = $config['spool']['incoming'] ?? null;
if ($backendType === 'android_sms_gateway') {
    $smsHandler = new AndroidSmsGatewayHandler($config['android_sms_gateway'], $debug, $config['spool']);
} else {
    $smsHandler = new Modem($config['modem'], $debug);
}


// --- Webhook Auto-Configuration ---
if ($backendType === 'android_sms_gateway' && ($config['android_sms_gateway']['webhook']['enabled'] ?? false)) {
    $webhookUrl = $config['android_sms_gateway']['webhook']['url'] ?? '';
    if (!empty($webhookUrl)) {
        log_message("VERBOSE: Starting Webhook Auto-Configuration for Android SMS Gateway.");
        log_message("VERBOSE: Target Webhook URL: {$webhookUrl}");

        $existingWebhooks = $smsHandler->listWebhooks();
        log_message("VERBOSE: Found " . count($existingWebhooks) . " existing webhook(s).");

        foreach ($existingWebhooks as $wh) {
            log_message("VERBOSE: Removing existing webhook ID {$wh['id']} (URL: {$wh['url']})");
            if ($smsHandler->deleteWebhook($wh['id'])) {
                log_message("VERBOSE: Successfully removed webhook ID {$wh['id']}.");
            } else {
                log_message("VERBOSE: Failed to remove webhook ID {$wh['id']}.");
            }
        }

        log_message("VERBOSE: Registering new webhook URL: {$webhookUrl}");
        if ($smsHandler->registerWebhook($webhookUrl, 'sms:received')) {
            log_message("SUCCESS: Registered webhook: {$webhookUrl}");
        } else {
            log_message("ERROR: Failed to register webhook: {$webhookUrl}");
        }
        log_message("VERBOSE: Webhook configuration step complete.");
    } else {
        log_message("WARNING: Webhook enabled but no URL configured in android_sms_gateway.webhook.url");
    }
}

$pluginManager = new PluginManager(ROOT_DIR . '/Plugins', $config);

$outgoingDir = $config['spool']['outgoing'];
$failedDir = $config['spool']['failed'];
if (!is_dir($failedDir)) {
    @mkdir($failedDir, 0755, true);
}

// --- Main Loop ---
log_message("Entering main processing loop using backend: {$backendType}");
while (true) {
    $currentStatus = [
        'backend' => $backendType,
        'last_cycle_start' => date('Y-m-d H:i:s'),
        'messages_sent' => 0,
        'messages_received' => 0,
        'errors' => []
    ];

    try {
        // --- Connection Management ---
        if (!$smsHandler->isConnected()) {
            log_message("SMS Handler is not connected. Attempting to connect...");
            if (!$smsHandler->connect()) {
                log_message("SMS Handler connection failed. Sleeping for 10 seconds before retry.");
                sleep(10);
                continue; // Restart the loop to try connecting again
            }
            log_message("SMS Handler connected successfully.");
        }

        // --- 1. Process Outgoing Messages ---
        log_message("Checking for outgoing messages in {$outgoingDir}");
        $files = @scandir($outgoingDir) ?: [];
        $sentCount = 0;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filePath = $outgoingDir . $file;
            if (!is_file($filePath)) continue;

            log_message("Processing file: {$file}");
            $message = file_get_contents($filePath);
            $number = substr($file, -10);

            if (strlen($number) !== 10) {
                 log_message("Invalid phone number format in filename: {$file}. Moving to failed directory.");
                 @rename($filePath, $failedDir . $file);
                 continue;
            }

            $outgoingMessageData = ['number' => $number, 'message' => $message];
            $processedMessageData = $pluginManager->dispatchOutgoing($outgoingMessageData);

            if ($processedMessageData['status'] === 'withheld') {
                log_message("Outgoing message to {$number} was withheld by plugin: " . ($processedMessageData['withhold_reason'] ?? 'Unknown') . ". Deleting from spool.");
                @unlink($filePath);
            } else {
                log_message("Sending message to {$processedMessageData['number']} after plugin processing.");
                if ($smsHandler->sendMessage($processedMessageData['number'], $processedMessageData['message'])) {
                    log_message("Successfully sent message from file {$file}. Deleting file.");
                    @unlink($filePath);
                    $currentStatus['messages_sent']++;
                } else {
                    log_message("Failed to send message from file {$file} (Backend Error). Moving to failed directory.");
                    @rename($filePath, $failedDir . $file);
                }
            }

            $sentCount++;
            if ($sentCount >= ($config['daemon']['send_batch_size'] ?? 10)) {
                log_message("Sent batch of {$sentCount}. Pausing sending to allow for receiving.");
                break;
            }

            // Pause between sends to be polite to the modem
            sleep($config['daemon']['send_interval']);
        }
        if ($sentCount > 0) {
            log_message("Finished processing outgoing messages for this cycle.");
        }

        // --- 2. Process Incoming Messages ---
        log_message("Checking for incoming messages.");
        $incomingMessages = $smsHandler->listMessages();
        if (!empty($incomingMessages)) {
            log_message("Found " . count($incomingMessages) . " new message(s).");
            foreach ($incomingMessages as $msg) {
                log_message("Processing message ID {$msg['id']} from {$msg['sender']}.");
                $currentStatus['messages_received']++;
                $response = $pluginManager->dispatchIncoming($msg);

                if ($response) {
                    log_message("Plugin provided a response. Sending reply to {$msg['sender']}.");
                    $smsHandler->sendMessage($msg['sender'], $response);
                }

                log_message("Deleting processed message ID {$msg['id']} from backend.");
                if (!$smsHandler->deleteMessage($msg['id'])) {
                    log_message("Failed to delete message ID {$msg['id']}. Disconnecting handler to reset state.");
                    $smsHandler->disconnect();
                    $currentStatus['errors'][] = "Failed to delete message ID {$msg['id']}";
                    break; // Exit the inner message processing loop to restart the main loop
                }
            }
        } else {
            log_message("No new messages found.");
        }

    } catch (\Exception $e) {
        // Catch errors from the SMS Handler
        log_message("An exception occurred: " . $e->getMessage());
        log_message("Disconnecting SMS Handler due to error.");
        $smsHandler->disconnect();
        $currentStatus['errors'][] = $e->getMessage();
    }

    // --- Sleep before next cycle ---
    log_message("Main loop cycle finished. Sleeping for {$config['daemon']['loop_interval']} seconds.");
    $currentStatus['last_cycle_end'] = date('Y-m-d H:i:s');
    update_heartbeat($currentStatus);
    sleep($config['daemon']['loop_interval']);
}
