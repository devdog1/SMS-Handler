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
    private $user;
    private $password;
    private $sessionToken = null;
    private $debug;

    /**
     * ZabbixApi constructor.
     * @param string $apiUrl The URL of the Zabbix API (e.g., http://zabbix/api_jsonrpc.php).
     * @param string $user Zabbix username.
     * @param string $password Zabbix password.
     * @param bool $debug Whether to enable debug logging.
     */
    public function __construct(string $apiUrl, string $user, string $password, bool $debug = false)
    {
        $this->apiUrl = $apiUrl;
        $this->user = $user;
        $this->password = $password;
        $this->debug = $debug;
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            error_log("SMS-DAEMON-ZABBIXAPI: " . $message);
        }
    }

    /**
     * Authenticates with the Zabbix API and retrieves a session token.
     * @return bool True on success, false on failure.
     */
    public function login(): bool
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'user.login',
            'params' => [
                'username' => $this->user,
                'password' => $this->password,
            ],
            'id' => 1,
            'auth' => null,
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
            $this->log("Login CURL Error: {$error}");
            return false;
        }

        if ($httpCode !== 200) {
            $this->log("Login HTTP Error: {$httpCode}. Response: {$response}");
            return false;
        }

        $result = json_decode($response, true);
        if (isset($result['error'])) {
            $this->log("Login Zabbix API Error: " . json_encode($result['error']));
            return false;
        }

        $this->sessionToken = $result['result'] ?? null;
        return $this->sessionToken !== null;
    }

    /**
     * Logs out of the current Zabbix session.
     * @return bool
     */
    public function logout(): bool
    {
        if ($this->sessionToken === null) {
            return true;
        }

        $result = $this->request('user.logout', [], false);
        $this->sessionToken = null;

        return $result === true;
    }

    /**
     * Makes a request to the Zabbix API.
     * @param string $method The API method to call.
     * @param array $params The parameters for the method.
     * @param bool $allowRetry Whether to retry once on session failure.
     * @return mixed The result from the API, or null on failure.
     */
    public function request(string $method, array $params = [], bool $allowRetry = true)
    {
        if ($this->sessionToken === null && $method !== 'user.login') {
            if (!$this->login()) {
                return null;
            }
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'auth' => $this->sessionToken,
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
            // Zabbix returns -32602 for several errors, including session expiry.
            // Check if it's a session error and retry if allowed.
            if ($allowRetry && $method !== 'user.login' &&
                (strpos($result['error']['data'] ?? '', 'Session terminated') !== false ||
                 strpos($result['error']['message'] ?? '', 'Session terminated') !== false)) {
                $this->log("Session expired or invalid. Re-logging in...");
                $this->sessionToken = null;
                return $this->request($method, $params, false);
            }
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

        if (!is_array($users)) {
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

        if (is_array($events) && !empty($events)) {
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
        $result = $this->request('usergroup.get', [
            'output' => ['usrgrpid', 'name'],
            'selectUsers' => ['userid', 'username', 'name', 'surname'],
            'search' => ['name' => '*on-call*'],
            'searchWildcardsEnabled' => true,
        ]);

        return is_array($result) ? $result : [];
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

        if (!is_array($users)) return null;

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
     * Retrieves a single user group by ID, including its members.
     * @param int $userGroupId
     * @return array|null The group data or null if not found.
     */
    public function getUserGroupById(int $userGroupId): ?array
    {
        $groups = $this->request('usergroup.get', [
            'usrgrpids' => $userGroupId,
            'selectUsers' => ['userid', 'username', 'name', 'surname'],
        ]);

        return (is_array($groups) && !empty($groups)) ? $groups[0] : null;
    }

    /**
     * Checks if a specific user is a member of a specific user group.
     * @param int $userId
     * @param int $userGroupId
     * @return bool
     */
    public function isUserInGroup(int $userId, int $userGroupId): bool
    {
        $users = $this->request('user.get', [
            'userids' => $userId,
            'usrgrpids' => $userGroupId,
            'output' => ['userid'],
        ]);

        return (is_array($users) && !empty($users));
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
