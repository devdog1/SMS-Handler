<?php

require_once __DIR__ . '/../Lib/Polyfills.php';
require_once __DIR__ . '/../Lib/PluginManager.php';
require_once __DIR__ . '/../Plugins/IPlugin.php';
require_once __DIR__ . '/../Plugins/BasePlugin.php';
require_once __DIR__ . '/../Plugins/SmsLoggerPlugin.php';

use SmsDaemon\Lib\PluginManager;
use SmsDaemon\Plugins\BasePlugin;

// Mock plugins for testing
class MockWithholdPlugin extends BasePlugin {
    public function handleIncoming(array $message): ?string { return null; }
    public function handleOutgoing(array $messageData): ?array {
        return null; // Withhold the message
    }
}

class MockModifyPlugin extends BasePlugin {
    public function handleIncoming(array $message): ?string { return null; }
    public function handleOutgoing(array $messageData): ?array {
        $messageData['message'] .= ' [MODIFIED]';
        return $messageData;
    }
}

// We need to bypass the real SmsLoggerPlugin's DB connection for testing
class TestSmsLoggerPlugin extends \SmsDaemon\Plugins\SmsLoggerPlugin {
    public $loggedData = [];
    public function handleOutgoing(array $messageData): ?array {
        $this->loggedData[] = $messageData;
        return $messageData;
    }
}

// Manually register the mock plugins since PluginManager scans the directory
// For this test, we'll sub-class PluginManager to use our mocks

class TestPluginManager extends PluginManager {
    private $mockPlugins = [];
    public function __construct(array $plugins) {
        $this->mockPlugins = $plugins;
    }
    public function dispatchOutgoing(array $messageData): array {
        // Copy-paste logic from the real PluginManager or call it if we can inject
        // Since we want to test the logic we just added to PluginManager, let's use the real one but mock the internal plugins list

        $reflector = new ReflectionClass('SmsDaemon\Lib\PluginManager');
        $prop = $reflector->getProperty('plugins');
        $prop->setAccessible(true);
        $prop->setValue($this, $this->mockPlugins);
        $prop = $reflector->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue($this, ['debug' => true]);

        return parent::dispatchOutgoing($messageData);
    }
}

echo "Testing PluginManager dispatchOutgoing logic...\n";

$logger = new TestSmsLoggerPlugin(['debug' => true]);
$withholder = new MockWithholdPlugin(['debug' => true]);
$modifier = new MockModifyPlugin(['debug' => true]);

// 1. Test normal flow (sent)
echo "1. Testing normal flow (sent)...\n";
$pm = new TestPluginManager([$modifier, $logger]);
$msg = ['number' => '1234567890', 'message' => 'Hello'];
$result = $pm->dispatchOutgoing($msg);

if ($result['status'] !== 'sent') throw new Exception("Status should be 'sent'");
if (strpos($result['message'], '[MODIFIED]') === false) throw new Exception("Message should be modified");
if (count($logger->loggedData) !== 1) throw new Exception("Logger should have been called once");
if ($logger->loggedData[0]['status'] !== 'sent') throw new Exception("Logged status should be 'sent'");
echo "   PASSED\n";

// 2. Test withhold flow
echo "2. Testing withhold flow...\n";
$logger->loggedData = [];
$pm = new TestPluginManager([$withholder, $modifier, $logger]);
$msg = ['number' => '1234567890', 'message' => 'Hello'];
$result = $pm->dispatchOutgoing($msg);

if ($result['status'] !== 'withheld') throw new Exception("Status should be 'withheld'");
if ($result['withhold_reason'] !== 'MockWithholdPlugin') throw new Exception("Reason should be 'MockWithholdPlugin'");
if (strpos($result['message'], '[MODIFIED]') !== false) throw new Exception("Modifier should have been skipped");
if (count($logger->loggedData) !== 1) throw new Exception("Logger should still have been called once");
if ($logger->loggedData[0]['status'] !== 'withheld') throw new Exception("Logged status should be 'withheld'");
if ($logger->loggedData[0]['withhold_reason'] !== 'MockWithholdPlugin') throw new Exception("Logged reason should be 'MockWithholdPlugin'");
echo "   PASSED\n";

echo "\nAll PluginManager tests PASSED!\n";
