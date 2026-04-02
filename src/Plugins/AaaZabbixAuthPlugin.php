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
            $zabbixConfig['token'] ?? '',
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
            // Returning null here blocks the message from being processed further by other plugins.
            // However, we need to be careful: if we return null, PluginManager.dispatchIncoming
            // will continue to the next plugin unless we return a string or the plugin manager is modified.
            // Wait, looking at PluginManager.php:
            /*
            foreach ($this->plugins as $plugin) {
                $response = $plugin->handleIncoming($message);
                if ($response !== null) {
                    $this->log("Incoming message handled by " . get_class($plugin));
                    return $response;
                }
            }
            */
            // If I return null, it DOES NOT block others from handling it.
            // To block, I might need to return a special value or the PluginManager needs to change.
            // Or I can return a string that indicates "Unauthorized".
            // But the requirement says "only allow incoming sms messages that match a phone number listed as a users media".
            // This sounds like a filter.

            // If I want to STOP processing, I should probably return something.
            // If I return a string, it will be sent as a reply.
            // If I return null, it continues to next plugin.

            // Maybe I should name it "AaaZabbixAuthPlugin" so it runs first.
            // And if it's NOT authorized, it should return something to stop others,
            // but maybe we don't want to reply to unauthorized numbers to avoid spam loops.

            // Let's re-examine how to block in this architecture.
            // BlockNumberPlugin also returns null.
            /*
            public function handleIncoming(array $message): ?string
            {
                if (in_array($message['sender'], $this->blockList)) {
                    $this->log("Blocking incoming message from blocked number: {$message['sender']}");
                    return null; // Silently drop the message
                }
                return null; // This plugin does not generate responses, so always return null for unblocked numbers.
            }
            */
            // Actually, if BlockNumberPlugin returns null, the PluginManager CONTINUES to the next plugin!
            // That means BlockNumberPlugin doesn't actually block unless it's the last one or it returns a string.
            // Wait, let's look at `sms_daemon.php`:
            /*
            $response = $pluginManager->dispatchIncoming($msg);
            if ($response) {
                log_message("Plugin provided a response. Sending reply to {$msg['sender']}.");
                $modem->sendMessage($msg['sender'], $response);
            }
            log_message("Deleting processed message ID {$msg['id']} from modem.");
            $modem->deleteMessage($msg['id']);
            */
            // If dispatchIncoming returns null, nothing happens (no reply), and the message is deleted.
            // But we want to PREVENT other plugins from handling it.

            // If I want to block, I should probably return a non-null value that doesn't result in a reply.
            // But `sms_daemon.php` sends the response if it's truthy.

            // Re-reading `IPlugin.php` or `BasePlugin.php`... they don't have a "stop propagation" mechanism other than returning a string.

            // If I return an empty string '', `sms_daemon.php` will see it as falsy and won't send a reply.
            // `if ($response)` is false for empty string.
            // AND `dispatchIncoming` will return it, so it will STOP the loop!

            /* PluginManager.php:
            if ($response !== null) {
                $this->log("Incoming message handled by " . get_class($plugin));
                return $response;
            }
            */
            // YES! If I return '', it's !== null, so it stops the loop, AND `sms_daemon.php` won't send a reply.

            return ''; // Stop processing, no reply.
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
