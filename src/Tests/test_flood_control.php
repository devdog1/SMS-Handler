<?php

require_once __DIR__ . '/../Lib/Polyfills.php';
require_once __DIR__ . '/../Plugins/IPlugin.php';
require_once __DIR__ . '/../Plugins/BasePlugin.php';
require_once __DIR__ . '/../Plugins/FloodControlPlugin.php';

use SmsDaemon\Plugins\FloodControlPlugin;

// Mock the environment
$tempSpool = sys_get_temp_dir() . '/sms_spool_test_' . uniqid();
mkdir($tempSpool);
$floodLock = sys_get_temp_dir() . '/sms_daemon_flood_mode.lock';
if (file_exists($floodLock)) unlink($floodLock);

$config = [
    'debug' => true,
    'spool' => ['outgoing' => $tempSpool],
    'plugins' => [
        'flood_control' => [
            'threshold' => 5,
            'summary_number' => '5551234567'
        ]
    ]
];

$plugin = new FloodControlPlugin($config);

function cleanup($dir, $lock) {
    $files = glob($dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($dir);
    if (file_exists($lock)) unlink($lock);
}

try {
    echo "Testing FloodControlPlugin...\n";

    // 1. Test normal operation (below threshold)
    echo "1. Testing normal operation (below threshold)...\n";
    for ($i = 0; $i < 3; $i++) {
        touch($tempSpool . '/msg' . $i . '1234567890');
    }
    $messageData = ['number' => '9876543210', 'message' => 'Test message'];
    $result = $plugin->handleOutgoing($messageData);
    if ($result === null) {
        throw new Exception("Plugin suppressed message incorrectly below threshold.");
    }
    echo "   PASSED\n";

    // 2. Test threshold reached (flood detection)
    echo "2. Testing flood detection (threshold reached)...\n";
    for ($i = 3; $i < 5; $i++) {
        touch($tempSpool . '/msg' . $i . '1234567890');
    }
    $files = scandir($tempSpool);
    echo "Current spool files count: " . (count($files) - 2) . "\n";
    // Spool count is now 5. Threshold is 5.
    $result = $plugin->handleOutgoing($messageData);
    if ($result !== null) {
        throw new Exception("Plugin failed to suppress message at threshold.");
    }
    if (!file_exists($floodLock)) {
        throw new Exception("Flood lock file was not created.");
    }

    // Check if summary message was queued
    $files = scandir($tempSpool);
    echo "Files in spool: " . implode(', ', $files) . "\n";
    $foundSummary = false;
    foreach ($files as $file) {
        if (strpos($file, '5551234567') !== false) {
            $content = file_get_contents($tempSpool . '/' . $file);
            if (strpos($content, '[FLOOD ALERT]') !== false) {
                $foundSummary = true;
                break;
            }
        }
    }
    if (!$foundSummary) {
        throw new Exception("Summary message was not queued.");
    }
    echo "   PASSED\n";

    // 3. Test lockout (suppression)
    echo "3. Testing lockout (further suppression)...\n";
    $result = $plugin->handleOutgoing($messageData);
    if ($result !== null) {
        throw new Exception("Plugin allowed message during lockout.");
    }
    echo "   PASSED\n";

    // 4. Test summary message bypass
    echo "4. Testing summary message bypass...\n";
    $summaryData = ['number' => '+1 (555) 123-4567', 'message' => '[FLOOD ALERT] summary'];
    $result = $plugin->handleOutgoing($summaryData);
    if ($result === null) {
        throw new Exception("Plugin suppressed its own summary message during lockout.");
    }
    echo "   PASSED\n";

    echo "\nAll FloodControlPlugin tests PASSED!\n";

} catch (Exception $e) {
    echo "\nTEST FAILED: " . $e->getMessage() . "\n";
    cleanup($tempSpool, $floodLock);
    exit(1);
}

cleanup($tempSpool, $floodLock);
