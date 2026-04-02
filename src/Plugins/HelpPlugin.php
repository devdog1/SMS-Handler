<?php

namespace SmsDaemon\Plugins;

use SmsDaemon\Lib\ZabbixApi;

/**
 * Class HelpPlugin
 *
 * Provides a help menu for authorized users.
 *
 * Command: "help"
 */
class HelpPlugin extends BasePlugin
{
    private $zabbixApi;
    private $authCacheFile;
    private $authCacheDuration;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $zabbixConfig = $config['zabbix'] ?? [];
        $this->zabbixApi = new ZabbixApi(
            $zabbixConfig['url'] ?? '',
            $zabbixConfig['user'] ?? '', $zabbixConfig['password'] ?? '',
            $this->debug
        );
        $this->authCacheDuration = $zabbixConfig['cache_duration'] ?? 3600;
        $this->authCacheFile = sys_get_temp_dir() . '/sms_daemon_zabbix_numbers.json';
    }

    /**
     * Handles the "help" command.
     */
    public function handleIncoming(array $message): ?string
    {
        if (strtolower(trim($message['text'])) !== 'help') {
            return null;
        }

        if (!$this->isAuthorized($message['sender'])) {
            $this->log("Unauthorized help command from {$message['sender']}. Ignoring.");
            return null;
        }

        $helpText = "Available commands:\n";
        $helpText .= "- pause <min>: Global pause (max 180).\n";
        $helpText .= "- pause status: Check pause status.\n";
        $helpText .= "- unpause: Resume system.\n";
        $helpText .= "- disable E:<id>: Disable Zabbix trigger.\n";
        $helpText .= "- oncall: List on-call members.\n";
        $helpText .= "- oncall <group>: Switch on-call to yourself.\n";
        $helpText .= "- history: Get last 5 sent messages.\n";
        $helpText .= "- <min> E:<id>: Ack Zabbix event.\n";
        $helpText .= "- ok, go, fuck: Bulk ack recent alerts.";

        return $helpText;
    }

    private function isAuthorized(string $sender): bool
    {
        $senderSanitized = $this->sanitizePhoneNumber($sender);
        $allowedNumbers = $this->getAllowedNumbers();
        return in_array($senderSanitized, $allowedNumbers);
    }

    private function getAllowedNumbers(): array
    {
        if (file_exists($this->authCacheFile) && (time() - filemtime($this->authCacheFile) < $this->authCacheDuration)) {
            $data = json_decode(file_get_contents($this->authCacheFile), true);
            if (is_array($data)) return $data;
        }

        $numbers = $this->zabbixApi->getAllUserMediaPhoneNumbers();
        if (!empty($numbers)) {
            file_put_contents($this->authCacheFile, json_encode($numbers));
        } elseif (file_exists($this->authCacheFile)) {
            $data = json_decode(file_get_contents($this->authCacheFile), true);
            if (is_array($data)) return $data;
        }

        return $numbers;
    }

}
