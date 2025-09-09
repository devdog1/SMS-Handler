<?php

namespace SmsDaemon\Plugins;

/**
 * Class BlockNumberPlugin
 *
 * An example plugin that demonstrates cancelling an outgoing message.
 * It blocks any message intended for a specific hardcoded phone number.
 */
class BlockNumberPlugin extends BasePlugin
{
    private const BLOCKED_NUMBER = '5556667777';

    /**
     * This plugin does not handle incoming messages, so it returns null.
     *
     * @param array $message The incoming message data.
     * @return null
     */
    public function handleIncoming(array $message): ?string
    {
        return null;
    }

    /**
     * Checks if the recipient number is on the blocklist. If it is,
     * it cancels the message by returning null.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array|null The message data if allowed, or null to block.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        if ($messageData['number'] === self::BLOCKED_NUMBER) {
            $this->log("Blocking outgoing message to the configured blocked number: " . self::BLOCKED_NUMBER);
            return null; // Cancel the message
        }

        return $messageData; // Allow the message
    }
}
