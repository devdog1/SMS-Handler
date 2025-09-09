<?php

namespace SmsDaemon\Plugins;

/**
 * Class AckCheckPlugin
 *
 * Checks if an outgoing alert has already been acknowledged to prevent
 * sending duplicate notifications.
 */
class AckCheckPlugin extends BasePlugin
{
    /**
     * This plugin does not handle incoming messages.
     */
    public function handleIncoming(array $message): ?string
    {
        return null;
    }

    /**
     * Checks for an active acknowledgement before sending a message.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array|null The message data if sending is allowed, or null to block.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        // Try to find an eventId in the message body. If none, we can't check for an ack.
        $eventId = null;
        if (preg_match('/E:(\d+)/', $messageData['message'], $matches)) {
            $eventId = (int)$matches[1];
        } else {
            return $messageData; // Not an alert with an eventId, allow it.
        }

        if ($this->dbConnect()) {
            $query = "SELECT id FROM acknowledgements WHERE eventId = ? AND sendTo = ? AND NOW() <= DATE_ADD(startTime, INTERVAL duration MINUTE) LIMIT 1";
            $stmt = $this->dbh->prepare($query);

            if ($stmt) {
                $recipient = $messageData['number'];
                $stmt->bind_param('is', $eventId, $recipient);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $this->log("Suppressing notification for eventId {$eventId} to {$recipient} due to active acknowledgement.");
                        $this->dbClose();
                        return null; // Block the message
                    }
                } else {
                    $this->log("Failed to query for acknowledgements. DB Error: " . $stmt->error);
                }
            } else {
                $this->log("Failed to prepare statement for ack check. DB Error: " . $this->dbh->error);
            }
            $this->dbClose();
        } else {
            $this->log("Database connection failed for Ack Check.");
        }

        // Default to allowing the message if DB check fails or no ack is found
        return $messageData;
    }
}
