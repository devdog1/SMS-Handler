<?php

namespace SmsDaemon\Plugins;

/**
 * Class AaaFloodControlPlugin
 *
 * Monitors the outgoing spool directory. If the number of pending messages
 * exceeds a threshold, it suppresses outgoing messages and sends a summary alert.
 */
class AaaFloodControlPlugin extends BasePlugin
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
                // Allow any flood summary alert messages to bypass the lockout
                if (strpos($messageData['message'], '[FLOOD ALERT]') !== false) {
                    $this->log("Allowing flood summary message to bypass lockout for {$targetNumber}.");
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

            // Remove existing pending messages for this number from the spool
            $result = $this->clearSpoolForNumber($targetNumber);
            $deletedCount = $result['count'];
            $summary = $result['summary'];
            $this->log("Deleted {$deletedCount} pending messages from spool for {$targetNumber}.");

            // Send summary alert messages to both administrator and recipient
            $this->sendSummary($targetNumber, $count, $summary);

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
     * Queues summary messages to be sent to both the administrator and the flooded recipient.
     * @param string $targetNumber The number being flooded.
     * @param int $count The current count of pending messages for that number.
     * @param string $summary A brief summary of suppressed message contents.
     */
    private function sendSummary(string $targetNumber, int $count, string $summary = ""): void
    {
        $adminMessage = "[FLOOD ALERT] There are currently {$count} pending SMS messages in the spool for number {$targetNumber}. Outgoing messages to this number are being suppressed for " . ($this->lockoutTime / 60) . " minutes.{$summary}";
        $recipientMessage = "[FLOOD ALERT] High volume of alerts detected. Further messages are being suppressed for " . ($this->lockoutTime / 60) . " minutes.{$summary}";

        // 1. Send to administrator if configured
        if (strlen($this->summaryNumber) === 10) {
            $filename = uniqid() . $this->summaryNumber;
            $filePath = rtrim($this->outgoingDir, '/') . '/' . $filename;
            if (file_put_contents($filePath, $adminMessage) !== false) {
                $this->log("Flood summary alert for {$targetNumber} queued for administrator {$this->summaryNumber}.");
            }
        } else {
            $this->log("No valid summary number configured. Administrator alert skipped.");
        }

        // 2. Send to recipient
        if (strlen($targetNumber) === 10) {
            $filename = uniqid() . $targetNumber;
            $filePath = rtrim($this->outgoingDir, '/') . '/' . $filename;
            if (file_put_contents($filePath, $recipientMessage) !== false) {
                $this->log("Flood summary alert queued for flooded recipient {$targetNumber}.");
            }
        }
    }

    /**
     * Deletes all files in the outgoing spool directory for a specific number.
     * @param string $number
     * @return array ['count' => int, 'summary' => string]
     */
    private function clearSpoolForNumber(string $number): array
    {
        $dir = rtrim($this->outgoingDir, '/') . '/';
        $files = @scandir($dir);
        if ($files === false) return ['count' => 0, 'summary' => ''];

        $count = 0;
        $contents = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filePath = $dir . $file;
            if (is_file($filePath) && substr($file, -10) === $number) {
                $content = @file_get_contents($filePath);
                if ($content !== false) {
                    $contents[] = trim($content);
                }
                if (@unlink($filePath)) {
                    $count++;
                }
            }
        }

        // Create a short summary of unique message contents
        $uniqueContents = array_unique($contents);
        $summary = "";
        if (!empty($uniqueContents)) {
            $summary = " Messages included: " . implode(", ", array_slice($uniqueContents, 0, 3));
            if (count($uniqueContents) > 3) {
                $summary .= "... (and more)";
            }
            // Limit summary length to avoid huge SMS
            if (strlen($summary) > 100) {
                $summary = substr($summary, 0, 97) . "...";
            }
        }

        return ['count' => $count, 'summary' => $summary];
    }
}
