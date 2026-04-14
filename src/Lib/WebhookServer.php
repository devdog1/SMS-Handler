<?php

namespace SmsDaemon\Lib;

/**
 * Class WebhookServer
 *
 * A simple non-blocking HTTP server to receive webhooks from Android SMS Gateway.
 */
class WebhookServer
{
    private $config;
    private $spoolDir;
    private $debug;
    private $server;

    public function __construct(array $config, string $spoolDir, bool $debug = false)
    {
        $this->config = $config;
        $this->spoolDir = rtrim($spoolDir, '/') . '/';
        $this->debug = $debug;

        if (!is_dir($this->spoolDir)) {
            @mkdir($this->spoolDir, 0755, true);
        }
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            error_log("SMS-DAEMON-WEBHOOK: " . $message);
        }
    }

    public function start(): bool
    {
        $port = $this->config['port'] ?? 8080;
        $this->server = @stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
        if (!$this->server) {
            $this->log("Failed to start webhook server on port $port: $errstr ($errno)");
            return false;
        }
        stream_set_blocking($this->server, false);
        $this->log("Webhook server started on port $port");
        return true;
    }

    public function handleClients(): void
    {
        if (!$this->server) return;

        while ($client = @stream_socket_accept($this->server, 0)) {
            // Reap zombie processes
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0);

            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->log("Could not fork child process for webhook handling.");
                $this->processClient($client); // Fallback to serial processing
            } elseif ($pid) {
                // Parent process
                fclose($client); // Parent doesn't need this
            } else {
                // Child process
                $this->processClient($client);
                exit(0);
            }
        }
    }

    private function processClient($client): void
    {
        $request = '';
        // Basic read with a small timeout/limit for simplicity
        $startTime = microtime(true);
        while (!feof($client) && microtime(true) - $startTime < 1.0) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') break;
            $request .= $chunk;
            if (strpos($request, "\r\n\r\n") !== false) {
                // Check if we have the full body if Content-Length is present
                if (preg_match('/Content-Length: (\d+)/i', $request, $matches)) {
                    $contentLength = (int)$matches[1];
                    $parts = explode("\r\n\r\n", $request, 2);
                    if (strlen($parts[1]) >= $contentLength) {
                        break;
                    }
                } else {
                    break;
                }
            }
        }

        if (empty($request)) {
            fclose($client);
            return;
        }

        $response = $this->handleRequest($request);
        fwrite($client, $response);
        fclose($client);
    }

    private function handleRequest(string $request): string
    {
        $lines = explode("\r\n", $request);
        $firstLine = array_shift($lines);
        if (!preg_match('/^POST\s+([^\s]+)\s+HTTP\/\d\.\d/i', $firstLine, $matches)) {
            return "HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\n\r\n";
        }

        $headers = [];
        foreach ($lines as $line) {
            if (empty($line)) break;
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim(strtolower($parts[0]))] = trim($parts[1]);
            }
        }

        $bodyParts = explode("\r\n\r\n", $request, 2);
        $body = $bodyParts[1] ?? '';

        // Verify HMAC if secret is configured
        if (!empty($this->config['secret'])) {
            $signature = $headers['x-signature'] ?? '';
            $timestamp = $headers['x-timestamp'] ?? '';
            if (!$this->verifySignature($this->config['secret'], $body, $timestamp, $signature)) {
                $this->log("Invalid HMAC signature from webhook.");
                return "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\n\r\n";
            }
        }

        $data = json_decode($body, true);
        if (!$data || !isset($data['event']) || $data['event'] !== 'sms:received') {
            return "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"; // Ack anyway to stop retries
        }

        $payload = $data['payload'];
        $messageData = [
            'id' => $data['id'],
            'sender' => $payload['sender'] ?? $payload['phoneNumber'],
            'message' => $payload['message'],
            'timestamp' => $payload['receivedAt'],
        ];

        $filename = $this->spoolDir . basename($data['id']);
        file_put_contents($filename, json_encode($messageData));
        $this->log("Stored incoming message from {$messageData['sender']} to {$filename}");

        return "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";
    }

    private function verifySignature($secret, $payload, $timestamp, $signature): bool
    {
        $message = $payload . $timestamp;
        $expectedSignature = hash_hmac('sha256', $message, $secret);
        return hash_equals($expectedSignature, $signature);
    }

    public function getServerResource()
    {
        return $this->server;
    }
}
