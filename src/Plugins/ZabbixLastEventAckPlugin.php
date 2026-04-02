<?php

namespace SmsDaemon\Plugins;

/**
 * Class ZabbixLastEventAckPlugin
 *
 * Acknowledges the last event sent to a phone number.
 * Commands:
 * - "ack": Acknowledges the last event for 24 hours (1440 minutes).
 * - "ack <minutes>": Acknowledges the last event for the specified duration.
 */
class ZabbixLastEventAckPlugin extends BasePlugin
{
    /**
     * @param array $message The incoming message.
     * @return string|null The response message or null.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = strtolower(trim($message['text']));
        $sender = $message['sender'];

        // Match "ack" or "ack <number>"
        if (preg_match('/^ack(\s+(\d+))?$/', $text, $matches)) {
            $duration = isset($matches[2]) ? (int)$matches[2] : 1440; // Default to 24 hours

            if ($duration <= 0) {
                return "Invalid duration. Please specify a number of minutes greater than 0.";
            }

            if ($this->dbConnect()) {
                // Find the last eventId sent to this number from the smsLog table.
                $query = "SELECT eventId FROM smsLog WHERE sendTo = ? AND eventId IS NOT NULL ORDER BY id DESC LIMIT 1";
                $stmt = $this->dbh->prepare($query);
                $stmt->bind_param('s', $sender);

                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $eventId = (int)$row['eventId'];

                        // Insert the acknowledgement
                        $ackQuery = "INSERT INTO acknowledgements (sendTo, eventId, startTime, duration) VALUES (?, ?, NOW(), ?)";
                        $ackStmt = $this->dbh->prepare($ackQuery);
                        $ackStmt->bind_param('sii', $sender, $eventId, $duration);

                        if ($ackStmt->execute()) {
                            $this->log("Successfully acknowledged last event {$eventId} for {$sender} for {$duration} minutes.");
                            $response = "You will not be messaged about E:{$eventId} for the next {$duration} minutes.";
                        } else {
                            $this->log("Failed to insert acknowledgement for event {$eventId}. Error: " . $ackStmt->error);
                            $response = "There was an error processing your acknowledgement.";
                        }
                    } else {
                        $this->log("No recent event found in smsLog for {$sender}.");
                        $response = "Could not find a recent event to acknowledge.";
                    }
                } else {
                    $this->log("Failed to query smsLog for last event. Error: " . $stmt->error);
                    $response = "Error: Could not retrieve your last event.";
                }
                $this->dbClose();
                return $response;
            } else {
                $this->log("Database connection failed for Zabbix Last Event Ack.");
                return "Error: Could not connect to the database to process your request.";
            }
        }

        return null; // Message not handled by this plugin.
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
            $this->log("Database connection failed for Ack Check in ZabbixLastEventAckPlugin.");
        }

        // Default to allowing the message if DB check fails or no ack is found
        return $messageData;
    }
}
