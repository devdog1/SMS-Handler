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
    public function handleIncoming(array $message): ?string;

    /**
     * Handles an outgoing message before it is sent.
     *
     * Can be used to modify the message, recipient, or to cancel sending altogether.
     *
     * @param array $messageData The data for the outgoing message. Example: ['number' => '+1234567890', 'message' => 'Hello world']
     *
     * @return array|null The modified message data. Return null to cancel sending the message.
     */
    public function handleOutgoing(array $messageData): ?array;
}
