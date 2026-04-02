<?php

namespace SmsDaemon\Lib;

/**
 * Class ZabbixApi
 *
 * Provides a simple interface to interact with the Zabbix 6.4 API.
 */
class ZabbixApi
{
    private $apiUrl;
    private $apiToken;
    private $debug;

    /**
     * ZabbixApi constructor.
     * @param string $apiUrl The URL of the Zabbix API (e.g., http://zabbix/api_jsonrpc.php).
     * @param string $apiToken A Zabbix API token.
     * @param bool $debug Whether to enable debug logging.
     */
    public function __construct(string $apiUrl, string $apiToken, bool $debug = false)
    {
        $this->apiUrl = $apiUrl;
        $this->apiToken = $apiToken;
        $this->debug = $debug;
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            error_log("SMS-DAEMON-ZABBIXAPI: " . $message);
        }
    }

    /**
     * Makes a request to the Zabbix API.
     * @param string $method The API method to call.
     * @param array $params The parameters for the method.
     * @return array|null The result from the API, or null on failure.
     */
    public function request(string $method, array $params = []): ?array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'auth' => $this->apiToken,
            'id' => 1,
        ];

        $jsonPayload = json_encode($payload);

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json-rpc',
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $this->log("CURL Error: {$error}");
            return null;
        }

        if ($httpCode !== 200) {
            $this->log("HTTP Error: {$httpCode}. Response: {$response}");
            return null;
        }

        $result = json_decode($response, true);
        if (isset($result['error'])) {
            $this->log("Zabbix API Error: " . json_encode($result['error']));
            return null;
        }

        return $result['result'] ?? null;
    }

    /**
     * Retrieves all phone numbers from user media.
     * @return array A list of unique phone numbers.
     */
    public function getAllUserMediaPhoneNumbers(): array
    {
        $users = $this->request('user.get', [
            'output' => ['userid', 'username'],
            'selectMedias' => ['sendto'],
        ]);

        if (!$users) {
            return [];
        }

        $phoneNumbers = [];
        foreach ($users as $user) {
            if (isset($user['medias']) && is_array($user['medias'])) {
                foreach ($user['medias'] as $media) {
                    $sendTo = $media['sendto'];
                    // If sendto is an array (multiple entries in Zabbix 6.0+), handle it.
                    if (is_array($sendTo)) {
                        foreach ($sendTo as $entry) {
                            $phoneNumbers[] = $this->sanitizePhoneNumber($entry);
                        }
                    } else {
                        $phoneNumbers[] = $this->sanitizePhoneNumber($sendTo);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($phoneNumbers)));
    }

    /**
     * Retrieves the trigger ID associated with a specific event ID.
     * @param int $eventId
     * @return int|null The trigger ID or null if not found.
     */
    public function getTriggerIdByEventId(int $eventId): ?int
    {
        $events = $this->request('event.get', [
            'eventids' => $eventId,
            'select_related_object' => ['triggerid'],
        ]);

        if ($events && !empty($events)) {
            $event = $events[0];
            if (isset($event['relatedObject']) && isset($event['relatedObject']['triggerid'])) {
                return (int)$event['relatedObject']['triggerid'];
            }
        }

        return null;
    }

    /**
     * Disables a specific trigger.
     * @param int $triggerId
     * @return bool True on success, false on failure.
     */
    public function disableTrigger(int $triggerId): bool
    {
        $result = $this->request('trigger.update', [
            'triggerid' => $triggerId,
            'status' => 1, // 1 means Disabled
        ]);

        return !empty($result);
    }

    /**
     * Retrieves all user groups containing "on-call" in their name and their members.
     * @return array
     */
    public function getOnCallGroups(): array
    {
        return $this->request('usergroup.get', [
            'output' => ['usrgrpid', 'name'],
            'selectUsers' => ['userid', 'username', 'name', 'surname'],
            'search' => ['name' => '*on-call*'],
            'searchWildcardsEnabled' => true,
        ]) ?? [];
    }

    /**
     * Retrieves the Zabbix user ID associated with a phone number.
     * @param string $phoneNumber The 10-digit sanitized phone number.
     * @return int|null The user ID or null if not found.
     */
    public function getUserIdByPhoneNumber(string $phoneNumber): ?int
    {
        $users = $this->request('user.get', [
            'output' => ['userid'],
            'selectMedias' => ['sendto'],
        ]);

        if (!$users) return null;

        foreach ($users as $user) {
            if (isset($user['medias']) && is_array($user['medias'])) {
                foreach ($user['medias'] as $media) {
                    $sendTo = $media['sendto'];
                    if (is_array($sendTo)) {
                        foreach ($sendTo as $entry) {
                            if ($this->sanitizePhoneNumber($entry) === $phoneNumber) {
                                return (int)$user['userid'];
                            }
                        }
                    } else {
                        if ($this->sanitizePhoneNumber($sendTo) === $phoneNumber) {
                            return (int)$user['userid'];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Updates the members of a Zabbix user group.
     * @param int $userGroupId
     * @param array $userIds
     * @return bool
     */
    public function setUserGroupMembers(int $userGroupId, array $userIds): bool
    {
        $result = $this->request('usergroup.update', [
            'usrgrpid' => $userGroupId,
            'userids' => $userIds,
        ]);

        return !empty($result);
    }

    /**
     * Sanitizes a phone number by removing non-numeric characters.
     * Keeps only the last 10 digits if possible, or the whole number if it's shorter.
     * @param string $number
     * @return string
     */
    private function sanitizePhoneNumber(string $number): string
    {
        // Remove all non-numeric characters
        $clean = preg_replace('/\D/', '', $number);

        // Return last 10 digits if longer than 10
        if (strlen($clean) > 10) {
            return substr($clean, -10);
        }

        return $clean;
    }
}
