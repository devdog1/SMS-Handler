<?php

namespace SmsDaemon\Plugins;

use SmsDaemon\Lib\ZabbixApi;

/**
 * Class BbbGlobalPausePlugin
 *
 * Allows authenticated users to temporarily pause outgoing SMS messages.
 * Authenticated users are those whose phone numbers are listed as media in Zabbix.
 *
 * Commands:
 * - "pause <minutes>": Pauses outgoing messages for the specified time.
 * - "pause status": Checks if a pause is active and how much time remains.
 * - "unpause": Removes the active pause.
 *
 * NOTE: Responses from this plugin to incoming messages are sent directly
 * by the modem and do not pass through the handleOutgoing() hook,
 * so they are NOT blocked by a global pause.
 */
class BbbGlobalPausePlugin extends BasePlugin
{
    private $zabbixApi;
    private $cacheFile;
    private $pauseCacheFile;
    private $cacheDuration;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $zabbixConfig = $config['zabbix'] ?? [];
        $this->zabbixApi = new ZabbixApi(
            $zabbixConfig['url'] ?? '',
            $zabbixConfig['token'] ?? '',
            $this->debug
        );
        $this->cacheDuration = $zabbixConfig['cache_duration'] ?? 3600;
        $this->cacheFile = sys_get_temp_dir() . '/sms_daemon_zabbix_numbers.json';
        $this->pauseCacheFile = sys_get_temp_dir() . '/sms_daemon_global_pause.json';
    }

    /**
     * Handles incoming messages for pause commands.
     * Only processes messages from authorized senders.
     *
     * @param array $message The incoming message.
     * @return string|null The response message or null.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = strtolower(trim($message['text']));
        $sender = $message['sender'];

        // Only handle specific commands
        if (!preg_match('/^(pause(\s+\d+|\s+status)?|unpause)$/', $text)) {
            return null;
        }

        // Authenticate the sender
        if (!$this->isAuthorized($sender)) {
            $this->log("Unauthorized pause command from {$sender}. Ignoring.");
            return null;
        }

        // 1. Check for "pause <minutes>"
        if (preg_match('/^pause\s+(\d+)$/', $text, $matches)) {
            $duration = (int)$matches[1];
            if ($duration <= 0) {
                return "Invalid duration. Please specify a number of minutes greater than 0.";
            }

            // Cap duration at 3 hours (180 minutes)
            if ($duration > 180) {
                $duration = 180;
                $this->log("Global pause duration capped at 180 minutes.");
            }

            if ($this->dbConnect()) {
                $this->dbh->query("DELETE FROM global_pause");
                $query = "INSERT INTO global_pause (startTime, duration, pausedBy) VALUES (NOW(), ?, ?)";
                $stmt = $this->dbh->prepare($query);
                $stmt->bind_param('is', $duration, $sender);

                if ($stmt->execute()) {
                    $this->log("Global pause started by {$sender} for {$duration} minutes.");
                    $this->updatePauseCache(time() + ($duration * 60));
                    $response = "System paused for {$duration} minutes by {$sender}.";
                } else {
                    $this->log("Failed to start global pause. Error: " . $stmt->error);
                    $response = "Error: Could not start global pause.";
                }
                $this->dbClose();
                return $response;
            }
            return "Error: Could not connect to the database.";
        }

        // 2. Check for "pause status"
        if ($text === 'pause status') {
            if ($this->dbConnect()) {
                $query = "SELECT startTime, duration, pausedBy,
                                 TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(startTime, INTERVAL duration MINUTE)) as secondsLeft
                          FROM global_pause
                          WHERE NOW() <= DATE_ADD(startTime, INTERVAL duration MINUTE)
                          ORDER BY id DESC LIMIT 1";
                $result = $this->dbh->query($query);

                if ($result && $row = $result->fetch_assoc()) {
                    $minutesLeft = ceil($row['secondsLeft'] / 60);
                    $response = "System is currently paused by {$row['pausedBy']}. {$minutesLeft} minute(s) remaining.";
                    $this->updatePauseCache(time() + (int)$row['secondsLeft']);
                } else {
                    $response = "System is not currently paused.";
                    $this->updatePauseCache(0);
                }
                $this->dbClose();
                return $response;
            }
            return "Error: Could not connect to the database.";
        }

        // 3. Check for "unpause"
        if ($text === 'unpause') {
            if ($this->dbConnect()) {
                if ($this->dbh->query("DELETE FROM global_pause")) {
                    $this->log("Global pause removed by {$sender}.");
                    $this->updatePauseCache(0);
                    $response = "System unpaused.";
                } else {
                    $this->log("Failed to remove global pause. Error: " . $this->dbh->error);
                    $response = "Error: Could not remove global pause.";
                }
                $this->dbClose();
                return $response;
            }
            return "Error: Could not connect to the database.";
        }

        return null;
    }

    /**
     * Blocks all outgoing messages if a global pause is active.
     * Uses a file-based cache to avoid frequent database queries.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array|null The message data if sending is allowed, or null to block.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        if ($this->isPaused()) {
            $this->log("Suppressing outgoing message to {$messageData['number']} due to active global pause.");
            return null;
        }

        return $messageData;
    }

    /**
     * Checks if a global pause is currently active.
     * @return bool
     */
    private function isPaused(): bool
    {
        // 1. Check the local file cache first
        if (file_exists($this->pauseCacheFile)) {
            $cache = json_decode(file_get_contents($this->pauseCacheFile), true);
            if (isset($cache['expiresAt'])) {
                if (time() < $cache['expiresAt']) {
                    return true;
                } elseif ($cache['expiresAt'] === 0) {
                    return false;
                }
            }
        }

        // 2. If cache is expired or missing, check the database
        if ($this->dbConnect()) {
            $query = "SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(startTime, INTERVAL duration MINUTE)) as secondsLeft
                      FROM global_pause
                      WHERE NOW() <= DATE_ADD(startTime, INTERVAL duration MINUTE)
                      LIMIT 1";
            $result = $this->dbh->query($query);

            if ($result && $row = $result->fetch_assoc()) {
                $expiresAt = time() + (int)$row['secondsLeft'];
                $this->updatePauseCache($expiresAt);
                $this->dbClose();
                return true;
            } else {
                $this->updatePauseCache(0);
                $this->dbClose();
                return false;
            }
        }

        return false;
    }

    /**
     * Updates the local file cache for the pause status.
     * @param int $expiresAt The Unix timestamp when the pause expires.
     */
    private function updatePauseCache(int $expiresAt): void
    {
        file_put_contents($this->pauseCacheFile, json_encode(['expiresAt' => $expiresAt]));
    }

    /**
     * Checks if the sender's phone number is authorized via Zabbix.
     * @param string $sender
     * @return bool
     */
    private function isAuthorized(string $sender): bool
    {
        $senderSanitized = $this->sanitizePhoneNumber($sender);
        $allowedNumbers = $this->getAllowedNumbers();

        return in_array($senderSanitized, $allowedNumbers);
    }

    /**
     * Gets the list of allowed phone numbers from Zabbix, with caching.
     * @return array
     */
    private function getAllowedNumbers(): array
    {
        if (file_exists($this->cacheFile) && (time() - filemtime($this->cacheFile) < $this->cacheDuration)) {
            $data = json_decode(file_get_contents($this->cacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        $numbers = $this->zabbixApi->getAllUserMediaPhoneNumbers();

        if (!empty($numbers)) {
            file_put_contents($this->cacheFile, json_encode($numbers));
        } elseif (file_exists($this->cacheFile)) {
            $data = json_decode(file_get_contents($this->cacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return $numbers;
    }

    /**
     * Sanitizes a phone number to match the format from ZabbixApi.
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
