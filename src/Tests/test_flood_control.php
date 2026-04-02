<?php

require_once __DIR__ . '/../Lib/Polyfills.php';
require_once __DIR__ . '/../Plugins/IPlugin.php';
require_once __DIR__ . '/../Plugins/BasePlugin.php';
require_once __DIR__ . '/../Plugins/AaaFloodControlPlugin.php';

use SmsDaemon\Plugins\AaaFloodControlPlugin;

// Mock the environment
$tempSpool = sys_get_temp_dir() . '/sms_spool_test_' . uniqid();
mkdir($tempSpool);

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

$plugin = new AaaFloodControlPlugin($config);

function cleanup($dir) {
    $files = glob($dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($dir);

    // Clean up possible lock files
    $locks = glob(sys_get_temp_dir() . '/sms_daemon_flood_*.lock');
    foreach ($locks as $lock) {
        if (is_file($lock)) unlink($lock);
    }
}

try {
    echo "Testing Per-Number AaaFloodControlPlugin...\n";

    $number1 = '9876543210';
    $number2 = '8887776666';

    // 1. Test normal operation for both numbers (below threshold)
    echo "1. Testing normal operation for both numbers (below threshold)...\n";
    for ($i = 0; $i < 3; $i++) {
        touch($tempSpool . '/msg' . $i . $number1);
        touch($tempSpool . '/msg' . $i . $number2);
    }
    $result1 = $plugin->handleOutgoing(['number' => $number1, 'message' => 'Test 1']);
    $result2 = $plugin->handleOutgoing(['number' => $number2, 'message' => 'Test 2']);
    if ($result1 === null || $result2 === null) {
        throw new Exception("Plugin suppressed message incorrectly below threshold.");
    }
    echo "   PASSED\n";

    // 2. Test threshold reached for number1 only
    echo "2. Testing flood detection for number1 only...\n";
    for ($i = 3; $i < 5; $i++) {
        touch($tempSpool . '/msg' . $i . $number1);
    }
    // Number1 spool count is now 5. Threshold is 5.
    $result1 = $plugin->handleOutgoing(['number' => $number1, 'message' => 'Trigger flood 1']);
    if ($result1 !== null) {
        throw new Exception("Plugin failed to suppress message for number1 at threshold.");
    }

    // Number2 should STILL be allowed
    $result2 = $plugin->handleOutgoing(['number' => $number2, 'message' => 'Test for number 2']);
    if ($result2 === null) {
        throw new Exception("Plugin suppressed number2 incorrectly when only number1 was flooded.");
    }
    echo "   PASSED\n";

    // 3. Verify summary message for number1
    echo "3. Verifying summary message for number1...\n";
    $files = scandir($tempSpool);
    $foundSummary = false;
    foreach ($files as $file) {
        if (strpos($file, '5551234567') !== false) {
            $content = file_get_contents($tempSpool . '/' . $file);
            if (strpos($content, '[FLOOD ALERT]') !== false && strpos($content, $number1) !== false) {
                $foundSummary = true;
                break;
            }
        }
    }
    if (!$foundSummary) {
        throw new Exception("Summary message for number1 was not queued correctly.");
    }
    echo "   PASSED\n";

    // 4. Test summary message bypass
    echo "4. Testing summary message bypass...\n";
    // If the summary number itself is also the one being flooded (unlikely but possible in test)
    $summaryData = ['number' => '5551234567', 'message' => '[FLOOD ALERT] summary for 9876543210'];
    $result = $plugin->handleOutgoing($summaryData);
    if ($result === null) {
        throw new Exception("Plugin suppressed its own summary message.");
    }
    echo "   PASSED\n";

    echo "\nAll Per-Number AaaFloodControlPlugin tests PASSED!\n";

} catch (Exception $e) {
    echo "\nTEST FAILED: " . $e->getMessage() . "\n";
    cleanup($tempSpool);
    exit(1);
}

cleanup($tempSpool);
