<?php

namespace SmsDaemon\Plugins;

/**
 * Class BlockNumberPlugin
 *
 * Blocks incoming and outgoing messages to and from numbers specified in the config file.
 */
class BlockNumberPlugin extends BasePlugin
{
    private $blockList = [];

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->blockList = $this->config['plugins']['block_numbers'] ?? [];
    }

    /**
     * Checks if the sender of an incoming message is on the blocklist.
     *
     * @param array $message The incoming message data.
     * @return null Returns null to silently drop the message if the sender is blocked.
     */
    public function handleIncoming(array $message): ?string
    {
        if (in_array($message['sender'], $this->blockList)) {
            $this->log("Blocking incoming message from blocked number: {$message['sender']}");
            return ''; // Silently drop the message and stop further processing
        }
        return null; // This plugin does not generate responses, so always return null for unblocked numbers.
    }

    /**
     * Checks if the recipient of an outgoing message is on the blocklist.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array|null The message data if allowed, or null to block.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        if (in_array($messageData['number'], $this->blockList)) {
            $this->log("Blocking outgoing message to blocked number: {$messageData['number']}");
            return null; // Cancel the message
        }

        return $messageData; // Allow the message
    }
}
