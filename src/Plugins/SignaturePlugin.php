<?php

namespace SmsDaemon\Plugins;

/**
 * Class SignaturePlugin
 *
 * An example plugin that demonstrates the outgoing message handling feature.
 * It appends a simple signature to every outgoing message.
 */
class SignaturePlugin extends BasePlugin
{
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
     * Appends a signature to every outgoing message.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array The modified message data.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        $signature = "\n-- Sent by SMS Daemon";

        // Prevent adding the signature if it's already there.
        if (strpos($messageData['message'], $signature) === false) {
            $this->log("Appending signature to message for {$messageData['number']}.");
            $messageData['message'] .= $signature;
        }

        return $messageData;
    }
}
