<?php

namespace SmsDaemon\Lib;

use \Exception;

/**
 * Class Modem
 *
 * Handles all communication with the GSM modem using AT commands.
 * This class abstracts the protocol details away from the main application.
 */
class Modem
{
    private $socket;
    private $config;
    private $debug;

    private const MAX_SMS_LENGTH = 160;

    public function __construct(array $config, bool $debug = false)
    {
        $this->config = $config;
        $this->debug = $debug;
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            error_log("SMS-DAEMON-MODEM: " . $message);
        }
    }

    /**
     * Connects to the modem and initializes it for sending/receiving SMS.
     * @return bool True on success, false on failure.
     */
    public function connect(): bool
    {
        try {
            $this->log("Connecting to modem at {$this->config['host']}:{$this->config['port']}");
            $this->socket = new Socket('tcp', (float)$this->config['timeout']);
            if (!$this->socket->connect($this->config['host'], $this->config['port'])) {
                $this->log("Socket connection failed.");
                $this->disconnect();
                return false;
            }
            $this->log("Socket connected. Initializing modem...");

            if (!$this->executeCommand("ATE0", "\r\nOK\r\n")) {
                $this->log("Failed to disable echo (ATE0).");
                $this->disconnect();
                return false;
            }
            $this->log("Echo disabled.");

            if (!$this->executeCommand("AT+CMGF=1", "\r\nOK\r\n")) {
                $this->log("Failed to set text mode (AT+CMGF=1).");
                $this->disconnect();
                return false;
            }
            $this->log("Text mode set. Modem is ready.");
            return true;
        } catch (Exception $e) {
            $this->log("Error during modem connection: " . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    /**
     * Disconnects from the modem.
     */
    public function disconnect(): void
    {
        if ($this->socket) {
            $this->socket->close();
            $this->socket = null;
            $this->log("Modem disconnected.");
        }
    }

    /**
     * Sends an SMS message. If the message exceeds 160 characters, it is split
     * into multiple parts and sent as separate SMS messages.
     *
     * @param string $number The recipient's phone number.
     * @param string $message The message text.
     * @return bool True only if ALL message parts were sent successfully.
     */
    public function sendMessage(string $number, string $message): bool
    {
        $maxLen = self::MAX_SMS_LENGTH;
        $messageLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

        if ($messageLen <= $maxLen) {
            return $this->sendRawMessage($number, $message);
        }

        $this->log("Message too long ($messageLen chars). Splitting into $maxLen-char parts.");
        $chunks = $this->splitMessage($message, $maxLen);

        $allSuccessful = true;
        foreach ($chunks as $idx => $chunk) {
            $part = $idx + 1;
            $this->log("Sending part $part of " . count($chunks));
            if (!$this->sendRawMessage($number, $chunk)) {
                $this->log("Failed to send part $part of message.");
                $allSuccessful = false;
            }
        }

        return $allSuccessful;
    }

    /**
     * Splits a message into chunks of a maximum length, safely handling multi-byte characters.
     *
     * @param string $message The message to split.
     * @param int $maxLen The maximum length of each chunk.
     * @return array The resulting message chunks.
     */
    protected function splitMessage(string $message, int $maxLen): array
    {
        if (function_exists('mb_str_split')) {
            return mb_str_split($message, $maxLen);
        }

        $chunks = [];
        $messageLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        for ($i = 0; $i < $messageLen; $i += $maxLen) {
            $chunks[] = function_exists('mb_substr')
                ? mb_substr($message, $i, $maxLen)
                : substr($message, $i, $maxLen);
        }
        return $chunks;
    }

    /**
     * Sends a single SMS message part without splitting.
     *
     * @param string $number The recipient's phone number.
     * @param string $message The message text.
     * @return bool True on success, false on failure.
     */
    private function sendRawMessage(string $number, string $message): bool
    {
        $this->log("Attempting to send message to {$number}");
        $this->socket->write("AT+CMGS=\"$number\"\r");
        $response = $this->socket->read(500);

        if (strpos($response, '> ') === false) {
            $this->log("Failed to get send prompt '>'. Response: " . trim($response));
            return false;
        }
        $this->log("Got send prompt. Writing message body.");
        $this->socket->write($message . chr(26));

        $full_response = '';
        for ($i = 0; $i < 10; $i++) { // Read for ~5 seconds
            $full_response .= $this->socket->read(500);
            if (preg_match("/\+CMGS: \d+/", $full_response)) {
                $this->log("Message sent successfully.");
                return true;
            }
            if (strpos($full_response, 'ERROR') !== false) {
                $this->log("Message send failed with ERROR. Response: " . trim($full_response));
                return false;
            }
        }

        $this->log("Send message timed out waiting for confirmation. Full response: " . trim($full_response));
        // On timeout, send ESC character (0x1B) to cancel message if it's stuck.
        $this->socket->write(chr(27));
        return false;
    }

    /**
     * Lists all messages stored on the modem.
     * @return array A list of messages, each as an associative array.
     */
    public function listMessages(): array
    {
        $this->log("Listing all messages from modem.");
        $this->socket->write("AT+CMGL=\"ALL\"\r");
        $buf = $this->readUntilTerminator();

        if (trim($buf) === '') {
            $this->log("Did not receive a response for AT+CMGL.");
            return [];
        }

        if (strpos($buf, '+CMGL:') === false) {
            $this->log("No messages on modem or unexpected response.");
            return [];
        }

        $messages = [];
        $rawMessages = explode("+CMGL: ", $buf);
        array_shift($rawMessages);

        foreach ($rawMessages as $rawMessage) {
            $pattern = '/(\d+),\"([^\"]+)\",\"([^\"]+)\",\"[^\"]*\",\"([^\"]+)\"\r\n(.*?)(\r\nOK\r\n|\Z)/s';
            if (preg_match($pattern, $rawMessage, $matches)) {
                $number = ltrim($matches[3], '+');
                // Also strip leading '1' for US-like numbers to get a 10-digit number
                if (strlen($number) === 11 && str_starts_with($number, '1')) {
                    $number = substr($number, 1);
                }
                $messages[] = [
                    'id' => (int)$matches[1],
                    'status' => $matches[2],
                    'sender' => $number,
                    'timestamp' => $matches[4],
                    'text' => trim($matches[5]),
                ];
            } else {
                $this->log("Could not parse message part: " . $rawMessage);
            }
        }
        $this->log("Found " . count($messages) . " messages.");
        return $messages;
    }

    /**
     * Deletes a message from the modem's storage.
     * @param int $id The ID of the message to delete.
     * @return bool True on success, false on failure.
     */
    public function deleteMessage(int $id): bool
    {
        $this->log("Deleting message ID {$id}");
        return $this->executeCommand("AT+CMGD={$id}", "\r\nOK\r\n");
    }

    /**
     * Executes a simple command and checks for an expected response.
     * @param string $command The AT command to send.
     * @param string $expectedResponse The exact response to wait for.
     * @return bool True if the expected response was received.
     */
    private function executeCommand(string $command, string $expectedResponse): bool
    {
        $this->socket->write($command . "\r");
        $response = $this->readUntilTerminator();
        if (strpos($response, trim($expectedResponse)) === false) {
            $this->log("Command '{$command}' failed. Expected '{$expectedResponse}', got '" . trim($response) . "'");
            return false;
        }
        return true;
    }

    /**
     * Reads from the socket in a loop until a terminator string is found or a timeout occurs.
     * @param array $terminators An array of strings to look for.
     * @param int $timeout The overall timeout in seconds for the operation.
     * @return string The buffer read from the socket.
     */
    private function readUntilTerminator(array $terminators = ['OK', 'ERROR'], int $timeout = 10): string
    {
        $buffer = '';
        $startTime = time();

        while (time() - $startTime < $timeout) {
            // Note: The socket itself has a read timeout (SO_RCVTIMEO).
            // This loop adds an overall timeout to the entire read operation.
            $chunk = $this->socket->read(2048);
            if ($chunk !== '') {
                $buffer .= $chunk;
                $trimmedBuffer = trim($buffer);
                foreach ($terminators as $terminator) {
                    if (str_ends_with($trimmedBuffer, $terminator)) {
                        return $buffer; // Found terminator, return immediately.
                    }
                }
            } else {
                // Small sleep to prevent a tight loop if socket is non-blocking
                // and there's no data.
                usleep(100000); // 100ms
            }
        }

        $this->log("Timed out after {$timeout} seconds waiting for one of: " . implode(', ', $terminators));
        return $buffer;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
