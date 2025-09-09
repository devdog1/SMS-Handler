<?php

namespace SmsDaemon\Plugins;

/**
 * Interface IPlugin
 *
 * Defines the contract for all plugins. Each plugin must be able to handle
 * an incoming message and decide whether to act upon it.
 */
interface IPlugin
{
    /**
     * Handles an incoming message.
     *
     * @param array $message The parsed message from the modem.
     *                       Example: ['id' => 1, 'status' => 'REC UNREAD', 'sender' => '+1234567890', 'timestamp' => '24/09/09,10:00:00+00', 'text' => 'Hello world']
     *
     * @return string|null A response message to be sent back to the sender.
     *                     Return null if this plugin does not handle the incoming message.
     */
    public function handle(array $message): ?string;
}
