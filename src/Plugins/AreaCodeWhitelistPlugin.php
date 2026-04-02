<?php

namespace SmsDaemon\Plugins;

/**
 * Class AreaCodeWhitelistPlugin
 *
 * Filters incoming and outgoing messages based on an area code whitelist
 * specified in the config file.
 */
class AreaCodeWhitelistPlugin extends BasePlugin
{
    private $whitelist = [];

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->whitelist = $this->config['plugins']['allowed_area_codes'] ?? [];
    }

    /**
     * Checks if the sender of an incoming message is from an allowed area code.
     */
    public function handleIncoming(array $message): ?string
    {
        // If the whitelist is empty, do not filter.
        if (empty($this->whitelist)) {
            return null;
        }

        $sender = $message['sender'];
        $areaCode = substr($sender, 0, 3);

        if (!in_array($areaCode, $this->whitelist)) {
            $this->log("Blocking incoming message from non-whitelisted area code: {$areaCode} ({$sender})");
            return ''; // Block by returning an empty string to stop processing
        }

        // Number is allowed, let other plugins handle it.
        return null;
    }

    /**
     * Checks if the recipient of an outgoing message is in an allowed area code.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        // If the whitelist is empty, do not filter.
        if (empty($this->whitelist)) {
            return $messageData;
        }

        $recipient = $messageData['number'];
        $areaCode = substr($recipient, 0, 3);

        if (!in_array($areaCode, $this->whitelist)) {
            $this->log("Blocking outgoing message to non-whitelisted area code: {$areaCode} ({$recipient})");
            return null; // Cancel the message
        }

        // Number is allowed, pass it on.
        return $messageData;
    }
}
