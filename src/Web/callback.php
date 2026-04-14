<?php

/**
 * Webhook receiver for Android SMS Gateway.
 * This script is intended to be served by a web server (e.g., Apache, Nginx).
 */

// Define root directory and load polyfills
define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/Lib/Polyfills.php';

// --- Configuration Loading ---
$config_file = ROOT_DIR . '/config.php';
$local_config_file = ROOT_DIR . '/config.local.php';

if (!file_exists($config_file)) {
    http_response_code(500);
    die("FATAL: Main configuration file not found.\n");
}
$config = require $config_file;

if (file_exists($local_config_file)) {
    $local_config = require $local_config_file;
    $config = array_replace_recursive($config, $local_config);
}

$webhookConfig = $config['android_sms_gateway']['webhook'] ?? [];
$debug = $config['debug'] ?? false;
$spoolDir = $config['spool']['incoming'] ?? '';

function log_webhook(string $message) {
    global $debug;
    if ($debug) {
        error_log("SMS-DAEMON-WEBHOOK: " . $message);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Method Not Allowed");
}

$body = file_get_contents('php://input');
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

// Debug dump if enabled
if (!empty($webhookConfig['debug_dump']) && !empty($webhookConfig['debug_dump_file'])) {
    $dump = "--- " . date('Y-m-d H:i:s') . " ---\n";
    $dump .= "HEADERS:\n" . print_r($headers, true) . "\n";
    $dump .= "BODY:\n" . $body . "\n\n";
    @file_put_contents($webhookConfig['debug_dump_file'], $dump, FILE_APPEND);
}

// Verify HMAC if secret is configured
if (!empty($webhookConfig['secret'])) {
    $signature = $headers['x-signature'] ?? '';
    $timestamp = $headers['x-timestamp'] ?? '';

    // Validate timestamp (within 5 minutes) to prevent replay attacks
    if (abs(time() - (int)$timestamp) > 300) {
        log_webhook("Webhook timestamp too old or too far in the future.");
        http_response_code(401);
        die("Unauthorized");
    }

    // Concatenation order matches the official documentation examples (body + timestamp)
    $message = $body . $timestamp;
    $expectedSignature = hash_hmac('sha256', $message, $webhookConfig['secret']);

    if (!hash_equals($expectedSignature, $signature)) {
        log_webhook("Invalid HMAC signature from webhook.");
        http_response_code(401);
        die("Unauthorized");
    }
}

$data = json_decode($body, true);
if (!$data || !isset($data['event']) || $data['event'] !== 'sms:received') {
    // Ack anyway to stop retries if it's an event we don't care about
    http_response_code(200);
    exit;
}

if (empty($spoolDir)) {
    log_webhook("Incoming spool directory not configured.");
    http_response_code(500);
    die("Internal Server Error");
}

if (!is_dir($spoolDir)) {
    @mkdir($spoolDir, 0755, true);
}

$payload = $data['payload'];
$messageData = [
    'id' => $data['id'],
    'sender' => $payload['sender'] ?? $payload['phoneNumber'],
    'message' => $payload['message'],
    'timestamp' => $payload['receivedAt'],
];

$filename = rtrim($spoolDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($data['id']);
if (file_put_contents($filename, json_encode($messageData)) === false) {
    log_webhook("Failed to write incoming message to {$filename}");
    http_response_code(500);
    die("Internal Server Error");
}

log_webhook("Stored incoming message from {$messageData['sender']} to {$filename}");
http_response_code(200);
echo "OK";
