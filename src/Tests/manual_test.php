<?php

require_once __DIR__ . '/../Lib/Polyfills.php';
require_once __DIR__ . '/../Lib/Modem.php';
require_once __DIR__ . '/../Lib/Socket.php';

use SmsDaemon\Lib\Modem;
use SmsDaemon\Lib\Socket;

class MockSocket extends Socket
{
    public $calls = [];
    public $responses = [];
    public $responseIndex = 0;

    public function __construct() {}

    public function connect($host, $port): bool { return true; }

    public function write(string $data): int
    {
        $this->calls[] = $data;
        return strlen($data);
    }

    public function read(int $length = 1024): string
    {
        if ($this->responseIndex < count($this->responses)) {
            return $this->responses[$this->responseIndex++];
        }
        return '';
    }

    public function close(): void {}
}

function testSendMessageSplitsLongMessages()
{
    echo "Running testSendMessageSplitsLongMessages...\n";
    $socket = new MockSocket();
    $socket->responses = [
        "> ",
        "+CMGS: 1\r\nOK\r\n",
        "> ",
        "+CMGS: 2\r\nOK\r\n"
    ];

    $config = ['host' => 'localhost', 'port' => 5000, 'timeout' => 5];
    $modem = new Modem($config, true);

    $reflection = new ReflectionClass($modem);
    $property = $reflection->getProperty('socket');
    $property->setAccessible(true);
    $property->setValue($modem, $socket);

    $longMessage = str_repeat('A', 160) . str_repeat('B', 10);
    $result = $modem->sendMessage('1234567890', $longMessage);

    if (!$result) {
        throw new Exception("Test failed: sendMessage returned false.");
    }

    if (count($socket->calls) !== 4) {
        throw new Exception("Test failed: expected 4 write calls, got " . count($socket->calls));
    }

    if ($socket->calls[0] !== "AT+CMGS=\"1234567890\"\r") {
        throw new Exception("Test failed: unexpected first call: " . $socket->calls[0]);
    }

    if ($socket->calls[1] !== str_repeat('A', 160) . chr(26)) {
        throw new Exception("Test failed: unexpected first chunk: " . $socket->calls[1]);
    }

    if ($socket->calls[2] !== "AT+CMGS=\"1234567890\"\r") {
        throw new Exception("Test failed: unexpected second call: " . $socket->calls[2]);
    }

    if ($socket->calls[3] !== str_repeat('B', 10) . chr(26)) {
        throw new Exception("Test failed: unexpected second chunk: " . $socket->calls[3]);
    }

    echo "testSendMessageSplitsLongMessages PASSED.\n";
}

function testSendMessageDoesNotSplitShortMessages()
{
    echo "Running testSendMessageDoesNotSplitShortMessages...\n";
    $socket = new MockSocket();
    $socket->responses = [
        "> ",
        "+CMGS: 1\r\nOK\r\n"
    ];

    $config = ['host' => 'localhost', 'port' => 5000, 'timeout' => 5];
    $modem = new Modem($config, true);

    $reflection = new ReflectionClass($modem);
    $property = $reflection->getProperty('socket');
    $property->setAccessible(true);
    $property->setValue($modem, $socket);

    $result = $modem->sendMessage('1234567890', 'Hello');

    if (!$result) {
        throw new Exception("Test failed: sendMessage returned false.");
    }

    if (count($socket->calls) !== 2) {
        throw new Exception("Test failed: expected 2 write calls, got " . count($socket->calls));
    }

    if ($socket->calls[1] !== "Hello" . chr(26)) {
        throw new Exception("Test failed: unexpected chunk: " . $socket->calls[1]);
    }

    echo "testSendMessageDoesNotSplitShortMessages PASSED.\n";
}

try {
    testSendMessageSplitsLongMessages();
    testSendMessageDoesNotSplitShortMessages();
} catch (Exception $e) {
    echo "TEST FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
