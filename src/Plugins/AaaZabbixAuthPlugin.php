<?php

namespace SmsDaemon\Plugins;

use SmsDaemon\Lib\ZabbixApi;

/**
 * Class ZabbixAuthPlugin
 *
 * Authenticates incoming messages based on user media phone numbers in Zabbix.
 * Only allows messages from phone numbers listed in Zabbix.
 */
class AaaZabbixAuthPlugin extends BasePlugin
{
    private $zabbixApi;
    private $cacheDuration;
    private $cacheFile;

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
     * Checks if the sender's phone number is authorized via Zabbix.
     *
     * @param array $message The incoming message data.
     * @return string|null Returns null if unauthorized (blocks message), or null to continue processing if authorized.
     */
    public function handleIncoming(array $message): ?string
    {
        $sender = $this->sanitizePhoneNumber($message['sender']);
        $allowedNumbers = $this->getAllowedNumbers();

        if (!in_array($sender, $allowedNumbers)) {
            $this->log("Unauthorized message from {$message['sender']} (sanitized: {$sender}). Blocking.");
            // Returning an empty string stops the plugin chain but doesn't send a reply.
            return '';
        }

        $this->log("Authorized message from {$message['sender']}.");
        return null; // Continue to other plugins.
    }

    /**
     * Gets the list of allowed phone numbers, with caching.
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
            // If API fails, fallback to old cache if it exists
            $data = json_decode(file_get_contents($this->cacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return $numbers;
    }
}
