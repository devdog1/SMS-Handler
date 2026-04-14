<?php

namespace SmsDaemon\Lib;

use \Exception;

/**
 * Class AndroidSmsGatewayHandler
 *
 * Handles communication with the Android SMS Gateway API.
 */
class AndroidSmsGatewayHandler implements SmsHandlerInterface
{
    private $config;
    private $debug;
    private $connected = false;

    public function __construct(array $config, bool $debug = false)
    {
        $this->config = $config;
        $this->debug = $debug;
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            error_log("SMS-DAEMON-ANDROID-GATEWAY: " . $message);
        }
    }

    public function connect(): bool
    {
        $this->log("Connecting to Android SMS Gateway at {$this->config['baseUrl']}");
        // For this handler, "connect" just means we're ready to make requests.
        // We could potentially do a health check here.
        $this->connected = true;
        return true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->log("Disconnected from Android SMS Gateway.");
    }

    public function sendMessage(string $number, string $message): bool
    {
        $this->log("Sending message to {$number}");

        $url = rtrim($this->config['baseUrl'], '/') . '/messages';
        $payload = [
            'phoneNumbers' => [$number],
            'textMessage' => ['text' => $message],
        ];

        if (!empty($this->config['deviceId'])) {
            $payload['deviceId'] = $this->config['deviceId'];
        }

        $response = $this->makeRequest('POST', $url, $payload);

        if ($response && ($response['httpCode'] === 200 || $response['httpCode'] === 202)) {
            $this->log("Message sent successfully.");
            return true;
        }

        $this->log("Failed to send message. HTTP Code: " . ($response['httpCode'] ?? 'unknown'));
        return false;
    }

    public function listMessages(): array
    {
        // Android SMS Gateway primarily uses webhooks for incoming messages.
        // However, it has an export endpoint that triggers webhooks for existing messages.
        // This daemon's architecture expects a polling-style listMessages().
        // For now, we return an empty array as incoming messages should ideally be
        // handled via a separate webhook endpoint (which would need to be added to this project).
        // Alternatively, if the user really needs polling, we might need a different approach.
        $this->log("listMessages() called. Note: Android SMS Gateway typically uses webhooks for incoming messages.");
        return [];
    }

    public function deleteMessage($id): bool
    {
        // Deleting messages from the Android device via this API isn't a direct 1-to-1 with AT commands.
        $this->log("deleteMessage($id) called. Not implemented for Android SMS Gateway.");
        return true; // Return true to avoid blocking the loop
    }

    /**
     * Makes an HTTP request using cURL.
     */
    private function makeRequest(string $method, string $url, array $data = null): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Content-Type: application/json',
        ];

        if (!empty($this->config['login']) && !empty($this->config['password'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config['login'] . ":" . $this->config['password']);
        }

        if ($data !== null) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = 'Content-Length: ' . strlen($jsonData);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            $this->log("cURL Error: " . $error);
            return null;
        }

        return [
            'httpCode' => $httpCode,
            'body' => json_decode($responseBody, true),
        ];
    }
}
