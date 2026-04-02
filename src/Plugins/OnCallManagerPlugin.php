<?php

namespace SmsDaemon\Plugins;

use SmsDaemon\Lib\ZabbixApi;

/**
 * Class OnCallManagerPlugin
 *
 * Allows authenticated users to view on-call groups and switch on-call status for themselves.
 *
 * Commands:
 * - "oncall": Lists all user groups with "on-call" in the name and their members.
 * - "oncall <group_name>": Replaces current group members with the sender of the message.
 */
class OnCallManagerPlugin extends BasePlugin
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
            $zabbixConfig['token'] ?? '',
            $this->debug
        );
        $this->authCacheDuration = $zabbixConfig['cache_duration'] ?? 3600;
        $this->authCacheFile = sys_get_temp_dir() . '/sms_daemon_zabbix_numbers.json';
    }

    /**
     * Handles incoming messages for on-call management.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = strtolower(trim($message['text']));
        $sender = $message['sender'];

        // Only handle specific commands
        if (!str_starts_with($text, 'oncall')) {
            return null;
        }

        // Authenticate the sender
        if (!$this->isAuthorized($sender)) {
            $this->log("Unauthorized on-call command from {$sender}. Ignoring.");
            return null;
        }

        // Command: "oncall"
        if ($text === 'oncall') {
            $this->log("Listing on-call groups for {$sender}.");
            $groups = $this->zabbixApi->getOnCallGroups();

            if (empty($groups)) {
                return "No user groups with 'on-call' in the name found.";
            }

            $responseLines = [];
            foreach ($groups as $group) {
                $memberNames = [];
                if (isset($group['users']) && is_array($group['users'])) {
                    foreach ($group['users'] as $user) {
                        $name = trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''));
                        if (empty($name)) $name = $user['username'];
                        $memberNames[] = $name;
                    }
                }
                $members = empty($memberNames) ? "None" : implode(', ', $memberNames);
                $responseLines[] = "{$group['name']}: {$members}";
            }

            return "On-Call Groups:\n" . implode("\n", $responseLines);
        }

        // Command: "oncall <group_name>"
        if (preg_match('/^oncall\s+(.+)$/', $text, $matches)) {
            $groupName = trim($matches[1]);
            $this->log("Switching on-call for group '{$groupName}' to {$sender}.");

            $groups = $this->zabbixApi->getOnCallGroups();
            $targetGroup = null;

            foreach ($groups as $group) {
                if (strtolower($group['name']) === $groupName) {
                    $targetGroup = $group;
                    break;
                }
            }

            if (!$targetGroup) {
                return "Error: Group '{$groupName}' not found among on-call groups.";
            }

            $userId = $this->zabbixApi->getUserIdByPhoneNumber($this->sanitizePhoneNumber($sender));
            if (!$userId) {
                return "Error: Could not find your Zabbix user ID associated with this phone number.";
            }

            if ($this->zabbixApi->setUserGroupMembers($targetGroup['usrgrpid'], [$userId])) {
                $this->log("Successfully switched on-call for {$targetGroup['name']} to user ID {$userId}.");
                return "You are now on-call for {$targetGroup['name']}. Existing members have been removed.";
            } else {
                $this->log("Failed to switch on-call for {$targetGroup['name']}.");
                return "Error: Could not update the members for group '{$targetGroup['name']}'.";
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
        if (file_exists($this->authCacheFile) && (time() - filemtime($this->authCacheFile) < $this->authCacheDuration)) {
            $data = json_decode(file_get_contents($this->authCacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        $numbers = $this->zabbixApi->getAllUserMediaPhoneNumbers();

        if (!empty($numbers)) {
            file_put_contents($this->authCacheFile, json_encode($numbers));
        } elseif (file_exists($this->authCacheFile)) {
            $data = json_decode(file_get_contents($this->authCacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return $numbers;
    }

    /**
     * Sanitizes a phone number to match the format from ZabbixApi.
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
