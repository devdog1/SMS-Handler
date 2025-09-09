<?php

namespace SmsDaemon\Plugins;

/**
 * Class ZzzDefaultReplyPlugin
 *
 * This plugin acts as a catch-all for messages that are not handled by any other plugin.
 * The 'Zzz' prefix ensures it is loaded and executed last by the PluginManager.
 */
class ZzzDefaultReplyPlugin extends BasePlugin
{
    /**
     * Handles any message not processed by other plugins.
     *
     * @param array $message The incoming message.
     * @return string The default response message.
     */
    public function handleIncoming(array $message): ?string
    {
        $this->log("Message from {$message['sender']} was not handled by other plugins. Sending default reply.");

        // This response is taken from the original script's fallback case.
        return "You sent me a bad message; shame, shame, shame";
    }
}
