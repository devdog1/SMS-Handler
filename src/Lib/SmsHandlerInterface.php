<?php

namespace SmsDaemon\Lib;

/**
 * Interface SmsHandlerInterface
 *
 * Defines the contract for SMS backend handlers.
 */
interface SmsHandlerInterface
{
    /**
     * Connects to the SMS gateway/backend.
     * @return bool True on success, false on failure.
     */
    public function connect(): bool;

    /**
     * Checks if the handler is currently connected.
     * @return bool True if connected, false otherwise.
     */
    public function isConnected(): bool;

    /**
     * Disconnects from the SMS gateway/backend.
     */
    public function disconnect(): void;

    /**
     * Sends an SMS message.
     *
     * @param string $number The recipient's phone number.
     * @param string $message The message text.
     * @return bool True on success, false on failure.
     */
    public function sendMessage(string $number, string $message): bool;

    /**
     * Lists all incoming messages from the backend.
     * @return array A list of messages, each as an associative array.
     */
    public function listMessages(): array;

    /**
     * Deletes a message from the backend.
     * @param mixed $id The ID of the message to delete.
     * @return bool True on success, false on failure.
     */
    public function deleteMessage($id): bool;
}
