<?php

namespace SmsDaemon\Plugins;

/**
 * Class ZabbixAckPlugin
 *
 * Handles SMS messages to acknowledge a specific Zabbix event.
 * It looks for a message starting with a number (duration) and containing an event ID (E:12345).
 */
class ZabbixAckPlugin extends BasePlugin
{
    /**
     * @param array $message The incoming message.
     * @return string|null The response message or null.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = $message['text'];
        $sender = $message['sender'];

        // Looks for a number at the start, followed by "E:" and a number anywhere.
        // The /s modifier (dotall) ensures `.` matches newlines, for multi-line messages.
        if (preg_match('/^(\d+).*E:(\d+)/s', $text, $matches)) {
            $this->log("Handling Zabbix Ack message from {$sender}.");

            $duration = (int)$matches[1];
            if ($duration > 1440) {
                $duration = 1440; // Cap duration at 24 hours
                $this->log("Duration capped at 1440 minutes.");
            }

            $eventId = (int)$matches[2];

            if ($this->dbConnect()) {
                $query = "INSERT INTO acknowledgements (sendTo, eventId, startTime, duration) VALUES (?, ?, NOW(), ?)";
                $stmt = $this->dbh->prepare($query);
                $stmt->bind_param('sii', $sender, $eventId, $duration);

                if ($stmt->execute()) {
                    $this->log("Successfully inserted acknowledgement for event {$eventId}.");
                    $response = "You will not be messaged about E:{$eventId} for the next {$duration} minutes.";
                } else {
                    $this->log("Failed to insert acknowledgement for event {$eventId}. Error: " . $stmt->error);
                    $response = "There was an error processing your acknowledgement.";
                }
                $this->dbClose();
                return $response;
            } else {
                $this->log("Database connection failed for Zabbix Ack.");
                return "Error: Could not connect to the database to process your request.";
            }
        }

        return null; // Message not handled by this plugin.
    }
}
