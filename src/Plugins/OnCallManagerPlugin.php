<?php

namespace SmsDaemon\Plugins;

use SmsDaemon\Lib\ZabbixApi;

/**
 * Class OnCallManagerPlugin
 *
 * Allows authenticated users to view on-call groups and switch on-call status for themselves.
 * This version uses a configuration-based mapping of on-call groups to user pools.
 * A user can only switch on-call if they belong to the pool associated with the on-call group.
 *
 * Config mapping: 'plugins' => ['oncall_groups' => [oncall_usrgrpid => pool_usrgrpid, ...]]
 */
class OnCallManagerPlugin extends BasePlugin
{
    private $zabbixApi;
    private $authCacheFile;
    private $authCacheDuration;
    private $onCallGroupMap;

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
        $this->onCallGroupMap = $config['plugins']['oncall_groups'] ?? [];
    }

    /**
     * Handles incoming messages for on-call management.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = strtolower(trim($message['text']));
        $sender = $message['sender'];

        if (!str_starts_with($text, 'oncall')) {
            return null;
        }

        if (!$this->isAuthorized($sender)) {
            $this->log("Unauthorized on-call command from {$sender}. Ignoring.");
            return null;
        }

        // Command: "oncall"
        if ($text === 'oncall') {
            $this->log("Listing on-call groups for {$sender}.");

            if (empty($this->onCallGroupMap)) {
                return "On-call group mapping is not configured.";
            }

            $responseLines = [];
            foreach (array_keys($this->onCallGroupMap) as $usrgrpid) {
                $group = $this->zabbixApi->getUserGroupById($usrgrpid);
                if ($group) {
                    $memberNames = [];
                    foreach (($group['users'] ?? []) as $user) {
                        $name = trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''));
                        if (empty($name)) $name = $user['username'];
                        $memberNames[] = $name;
                    }
                    $members = empty($memberNames) ? "None" : implode(', ', $memberNames);
                    $responseLines[] = "{$group['name']}: {$members}";
                }
            }

            if (empty($responseLines)) {
                return "Could not retrieve any on-call groups.";
            }

            return "On-Call Status:\n" . implode("\n", $responseLines);
        }

        // Command: "oncall <group_name>"
        if (preg_match('/^oncall\s+(.+)$/', $text, $matches)) {
            $groupName = trim($matches[1]);
            $this->log("Switching on-call for group '{$groupName}' to {$sender}.");

            $userId = $this->zabbixApi->getUserIdByPhoneNumber($this->sanitizePhoneNumber($sender));
            if (!$userId) {
                return "Error: Could not find your Zabbix user ID.";
            }

            $targetUsrgrpid = null;
            $poolUsrgrpid = null;
            $finalGroupName = null;

            foreach ($this->onCallGroupMap as $oncallId => $poolId) {
                $group = $this->zabbixApi->getUserGroupById($oncallId);
                if ($group && strtolower($group['name']) === $groupName) {
                    $targetUsrgrpid = (int)$oncallId;
                    $poolUsrgrpid = (int)$poolId;
                    $finalGroupName = $group['name'];
                    break;
                }
            }

            if (!$targetUsrgrpid) {
                return "Error: On-call group '{$groupName}' not found in configuration mapping.";
            }

            // Check if user is in the required pool
            if (!$this->zabbixApi->isUserInGroup($userId, $poolUsrgrpid)) {
                $poolGroup = $this->zabbixApi->getUserGroupById($poolUsrgrpid);
                $poolName = $poolGroup ? $poolGroup['name'] : "ID $poolUsrgrpid";
                return "Error: You must be a member of '{$poolName}' to switch onto the '{$finalGroupName}' rotation.";
            }

            if ($this->zabbixApi->setUserGroupMembers($targetUsrgrpid, [$userId])) {
                $this->log("Successfully switched on-call for {$finalGroupName} to user ID {$userId}.");
                return "You are now on-call for {$finalGroupName}.";
            } else {
                $this->log("Failed to update members for {$finalGroupName}.");
                return "Error: Could not update the on-call group.";
            }
        }

        return null;
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
