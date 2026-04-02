<?php

namespace SmsDaemon\Plugins;

use SmsDaemon\Lib\ZabbixApi;

/**
 * Class ZabbixTriggerDisablePlugin
 *
 * Allows authenticated users to disable the Zabbix trigger that caused a specific event.
 *
 * Command: "disable E:<eventId>"
 */
class ZabbixTriggerDisablePlugin extends BasePlugin
{
    private $zabbixApi;
    private $cacheFile;
    private $cacheDuration;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $zabbixConfig = $config['zabbix'] ?? [];
        $this->zabbixApi = new ZabbixApi(
            $zabbixConfig['url'] ?? '',
            $zabbixConfig['user'] ?? '', $zabbixConfig['password'] ?? '',
            $this->debug
        );
        $this->cacheDuration = $zabbixConfig['cache_duration'] ?? 3600;
        $this->cacheFile = sys_get_temp_dir() . '/sms_daemon_zabbix_numbers.json';
    }

    /**
     * @param array $message The incoming message.
     * @return string|null The response message or null.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = strtolower(trim($message['text']));
        $sender = $message['sender'];

        if (preg_match('/^disable\s+e:(\d+)$/', $text, $matches)) {
            $eventId = (int)$matches[1];
            $this->log("Disable command received for event E:{$eventId} from {$sender}.");

            if (!$this->isAuthorized($sender)) {
                $this->log("Unauthorized disable command from {$sender}. Ignoring.");
                return null;
            }

            $triggerId = $this->zabbixApi->getTriggerIdByEventId($eventId);
            if ($triggerId) {
                if ($this->zabbixApi->disableTrigger($triggerId)) {
                    $this->log("Successfully disabled trigger ID {$triggerId} for event E:{$eventId}.");
                    return "Trigger for event E:{$eventId} has been disabled.";
                } else {
                    $this->log("Failed to disable trigger ID {$triggerId}.");
                    return "Error: Could not disable the trigger for event E:{$eventId}.";
                }
            } else {
                $this->log("Trigger not found for event E:{$eventId}.");
                return "Error: Could not find the trigger associated with event E:{$eventId}.";
            }
        }

        return null;
    }

    /**
     * Checks if the sender's phone number is authorized via Zabbix.
     */
    private function isAuthorized(string $sender): bool
    {
        $senderSanitized = $this->sanitizePhoneNumber($sender);
        $allowedNumbers = $this->getAllowedNumbers();

        return in_array($senderSanitized, $allowedNumbers);
    }

    /**
     * Gets the list of allowed phone numbers from Zabbix, with caching.
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
}
