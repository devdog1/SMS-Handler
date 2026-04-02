<?php

namespace SmsDaemon\Plugins;

/**
 * Class FloodControlPlugin
 *
 * Monitors the outgoing spool directory. If the number of pending messages
 * exceeds a threshold, it suppresses outgoing messages and sends a summary alert.
 */
class FloodControlPlugin extends BasePlugin
{
    private $threshold;
    private $summaryNumber;
    private $outgoingDir;
    private $lockoutTime;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $pluginConfig = $this->config['plugins']['flood_control'] ?? [];
        $this->threshold = $pluginConfig['threshold'] ?? 50;
        $this->summaryNumber = $this->sanitizePhoneNumber($pluginConfig['summary_number'] ?? '');
        $this->lockoutTime = $pluginConfig['lockout_time'] ?? 300; // Default 5 minutes
        $this->outgoingDir = $this->config['spool']['outgoing'] ?? '/var/spool/sms/';
    }

    /**
     * This plugin does not handle incoming messages.
     */
    public function handleIncoming(array $message): ?string
    {
        return null;
    }

    /**
     * Checks for spool flood and suppresses outgoing messages if necessary.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array|null The message data or null to suppress.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        if (empty($this->summaryNumber) || $this->threshold <= 0) {
            return $messageData;
        }

        $targetNumber = $this->sanitizePhoneNumber($messageData['number']);
        $floodFlagFile = sys_get_temp_dir() . '/sms_daemon_flood_' . $targetNumber . '.lock';

        // 1. Check if we are currently in a lockout period for this number
        if (file_exists($floodFlagFile)) {
            $mtime = @filemtime($floodFlagFile);
            if (time() - $mtime < $this->lockoutTime) {
                // Allow the summary message itself to bypass the lockout
                if ($targetNumber === $this->summaryNumber && strpos($messageData['message'], '[FLOOD ALERT]') !== false) {
                    $this->log("Allowing flood summary message to {$this->summaryNumber}.");
                    return $messageData;
                }

                $this->log("Flood lockout active for {$targetNumber}. Suppressing message.");
                return null;
            } else {
                $this->log("Flood lockout expired for {$targetNumber}.");
                @unlink($floodFlagFile);
            }
        }

        // 2. Check the current spool count for this specific number
        $count = $this->getSpoolCountForNumber($targetNumber);
        if ($count >= $this->threshold) {
            $this->log("Spool flood detected for {$targetNumber}: {$count} messages (threshold: {$this->threshold}). Entering lockout.");

            // Create the lockout flag file
            touch($floodFlagFile);

            // Send a summary alert message
            $this->sendSummary($targetNumber, $count);

            // Suppress the current message that triggered the detection
            return null;
        }

        return $messageData;
    }

    /**
     * Counts the number of files in the outgoing spool directory for a specific number.
     * @param string $number
     * @return int
     */
    private function getSpoolCountForNumber(string $number): int
    {
        $dir = rtrim($this->outgoingDir, '/') . '/';
        $files = @scandir($dir);
        if ($files === false) {
            $this->log("Failed to scan directory: {$dir}");
            return 0;
        }

        $count = 0;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            // The daemon uses the last 10 characters of the filename for the phone number.
            if (is_file($dir . $file) && substr($file, -10) === $number) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Queues a summary message to be sent via the daemon.
     * @param string $targetNumber The number being flooded.
     * @param int $count The current count of pending messages for that number.
     */
    private function sendSummary(string $targetNumber, int $count): void
    {
        $message = "[FLOOD ALERT] There are currently {$count} pending SMS messages in the spool for number {$targetNumber}. Outgoing messages to this number are being suppressed for " . ($this->lockoutTime / 60) . " minutes.";

        if (strlen($this->summaryNumber) !== 10) {
            $this->log("Invalid summary number configured: {$this->summaryNumber}. Cannot send summary.");
            return;
        }

        // The daemon expects the last 10 characters of the filename to be the phone number.
        $filename = uniqid() . $this->summaryNumber;
        $filePath = rtrim($this->outgoingDir, '/') . '/' . $filename;

        if (file_put_contents($filePath, $message) !== false) {
            $this->log("Flood summary alert for {$targetNumber} queued for {$this->summaryNumber} (File: {$filename}).");
        } else {
            $this->log("Failed to write flood summary message to spool at {$filePath}.");
        }
    }

    /**
     * Sanitizes a phone number to exactly 10 digits.
     * @param string $number
     * @return string
     */
    private function sanitizePhoneNumber(string $number): string
    {
        $clean = preg_replace('/\D/', '', $number);
        if (strlen($clean) > 10) {
            return substr($clean, -10);
        }
        return $clean;
    }
}
