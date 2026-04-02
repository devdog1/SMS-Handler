<?php

namespace SmsDaemon\Plugins;

use SmsDaemon\Lib\ZabbixApi;

/**
 * Class SpoolClearPlugin
 *
 * Allows authorized users to clear the outgoing SMS spool directory.
 * Authorization is based on Zabbix user group membership.
 *
 * Commands:
 * - "clear spool": Deletes all files in the outgoing spool directory.
 */
class SpoolClearPlugin extends BasePlugin
{
    private $zabbixApi;
    private $cacheFile;
    private $cacheDuration;
    private $authorizedGroupId;
    private $outgoingDir;

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
        $this->authorizedGroupId = $config['plugins']['spool_clear']['authorized_group_id'] ?? null;
        $this->outgoingDir = $config['spool']['outgoing'] ?? '/var/spool/sms/';

        $cacheSuffix = $this->authorizedGroupId ? "_{$this->authorizedGroupId}" : '';
        $this->cacheFile = sys_get_temp_dir() . "/sms_daemon_spool_clear_auth{$cacheSuffix}.json";
    }

    /**
     * Handles incoming messages for spool clear commands.
     *
     * @param array $message The incoming message.
     * @return string|null The response message or null.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = strtolower(trim($message['text']));
        $sender = $message['sender'];

        if ($text !== 'clear spool') {
            return null;
        }

        // Authenticate the sender
        if (!$this->isAuthorized($sender)) {
            $this->log("Unauthorized clear spool command from {$sender}.");
            return null;
        }

        $this->log("Authorized clear spool command from {$sender}. Clearing spool...");
        $clearedCount = $this->clearSpool();

        return "Spool cleared. Deleted {$clearedCount} pending messages.";
    }

    /**
     * Deletes all files in the outgoing spool directory.
     * @return int The number of files deleted.
     */
    private function clearSpool(): int
    {
        $files = glob(rtrim($this->outgoingDir, '/') . '/*');
        $count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        $this->log("Cleared {$count} files from the spool.");
        return $count;
    }

    /**
     * Checks if the sender's phone number is authorized via Zabbix group.
     * @param string $sender
     * @return bool
     */
    private function isAuthorized(string $sender): bool
    {
        if ($this->authorizedGroupId === null) {
            $this->log("SpoolClearPlugin: No authorized_group_id configured. Denying all.");
            return false;
        }

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

        $numbers = $this->zabbixApi->getAllUserMediaPhoneNumbers($this->authorizedGroupId);

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
