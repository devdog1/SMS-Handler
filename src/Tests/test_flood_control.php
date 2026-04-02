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
    echo "Testing Per-Number AaaFloodControlPlugin with Spool Clearing...\n";

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

    // 2. Test threshold reached for number1 only (flood detection and spool clearing)
    echo "2. Testing flood detection and spool clearing for number1...\n";
    for ($i = 3; $i < 5; $i++) {
        touch($tempSpool . '/msg' . $i . $number1);
    }
    // Number1 spool count is now 5. Threshold is 5.
    $result1 = $plugin->handleOutgoing(['number' => $number1, 'message' => 'Trigger flood 1']);
    if ($result1 !== null) {
        throw new Exception("Plugin failed to suppress message for number1 at threshold.");
    }

    // Number2 should STILL be allowed AND its spool should NOT be cleared
    $result2 = $plugin->handleOutgoing(['number' => $number2, 'message' => 'Test for number 2']);
    if ($result2 === null) {
        throw new Exception("Plugin suppressed number2 incorrectly when only number1 was flooded.");
    }

    // Check if number1 spool was cleared (ignoring the flood alert summary itself)
    $files = scandir($tempSpool);
    $number1Count = 0;
    foreach ($files as $file) {
        if (is_file($tempSpool . '/' . $file) && substr($file, -10) === $number1) {
            $content = file_get_contents($tempSpool . '/' . $file);
            if (strpos($content, '[FLOOD ALERT]') === false) {
                $number1Count++;
            }
        }
    }
    if ($number1Count > 0) {
        throw new Exception("Spool for number1 was not cleared! Found {$number1Count} regular files.");
    }

    // Check if number2 spool is still there
    $number2Count = 0;
    foreach ($files as $file) {
        if (is_file($tempSpool . '/' . $file) && substr($file, -10) === $number2) {
            $number2Count++;
        }
    }
    if ($number2Count < 3) {
        throw new Exception("Spool for number2 was cleared incorrectly! Found {$number2Count} files.");
    }
    echo "   PASSED\n";

    // 3. Verify summary messages (Administrator and Recipient)
    echo "3. Verifying summary messages for both administrator and recipient...\n";
    $files = scandir($tempSpool);
    $foundAdminSummary = false;
    $foundRecipientSummary = false;
    foreach ($files as $file) {
        if (strpos($file, '5551234567') !== false) {
            $content = file_get_contents($tempSpool . '/' . $file);
            if (strpos($content, '[FLOOD ALERT]') !== false && strpos($content, $number1) !== false) {
                $foundAdminSummary = true;
            }
        }
        if (strpos($file, $number1) !== false) {
            $content = file_get_contents($tempSpool . '/' . $file);
            if (strpos($content, '[FLOOD ALERT]') !== false && strpos($content, 'High volume') !== false) {
                $foundRecipientSummary = true;
            }
        }
    }
    if (!$foundAdminSummary) {
        throw new Exception("Administrator summary message was not queued correctly.");
    }
    if (!$foundRecipientSummary) {
        throw new Exception("Recipient summary message was not queued correctly.");
    }
    echo "   PASSED\n";

    echo "\nAll AaaFloodControlPlugin spool clearing tests PASSED!\n";

} catch (Exception $e) {
    echo "\nTEST FAILED: " . $e->getMessage() . "\n";
    cleanup($tempSpool);
    exit(1);
}

cleanup($tempSpool);
