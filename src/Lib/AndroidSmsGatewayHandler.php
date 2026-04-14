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

    public function __construct(array $config, bool $debug = false, array $spoolConfig = [])
    {
        $this->config = $config;
        $this->config['spool'] = $spoolConfig;
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
            'message' => $message,
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
        $incomingDir = $this->config['spool']['incoming'] ?? null;
        if (!$incomingDir || !is_dir($incomingDir)) {
            return [];
        }

        $messages = [];
        $files = @scandir($incomingDir) ?: [];
        $baseDir = rtrim($incomingDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filePath = $baseDir . $file;
            if (!is_file($filePath)) continue;

            $content = file_get_contents($filePath);
            $data = json_decode($content, true);
            if ($data) {
                // Ensure internal format is consistent
                $messages[] = [
                    'id' => $data['id'],
                    'sender' => $data['sender'],
                    'text' => $data['message'],
                    'timestamp' => $data['timestamp'],
                ];
            }
        }

        return $messages;
    }

    public function deleteMessage($id): bool
    {
        $incomingDir = $this->config['spool']['incoming'] ?? null;
        if (!$incomingDir) return false;

        $filePath = rtrim($incomingDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($id);
        if (file_exists($filePath)) {
            $this->log("Deleting message file: {$id}");
            return @unlink($filePath);
        }

        return false;
    }

    /**
     * Lists registered webhooks.
     */
    public function listWebhooks(): array
    {
        $url = rtrim($this->config['baseUrl'], '/') . '/webhooks';
        $response = $this->makeRequest('GET', $url);
        return is_array($response['body']) ? $response['body'] : [];
    }

    /**
     * Deletes a webhook.
     */
    public function deleteWebhook(string $id): bool
    {
        $url = rtrim($this->config['baseUrl'], '/') . '/webhooks/' . $id;
        $response = $this->makeRequest('DELETE', $url);
        return $response['httpCode'] === 204;
    }

    /**
     * Registers a new webhook.
     */
    public function registerWebhook(string $webhookUrl, string $event): bool
    {
        $url = rtrim($this->config['baseUrl'], '/') . '/webhooks';
        $payload = [
            'url' => $webhookUrl,
            'event' => $event,
        ];

        if (!empty($this->config['deviceId'])) {
            $payload['deviceId'] = $this->config['deviceId'];
        }

        $response = $this->makeRequest('POST', $url, $payload);
        return $response['httpCode'] === 201;
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
