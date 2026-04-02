<?php

namespace SmsDaemon\Plugins;

/**
 * Class NumberPausePlugin
 *
 * Allows users to pause outgoing SMS messages for their own phone number.
 * Commands:
 * - "stop": Pauses outgoing messages for the sender's number (indefinitely/1 year).
 * - "stop <minutes>": Pauses outgoing messages for the specified number of minutes.
 * - "go": Resumes outgoing messages for the sender's number.
 */
class NumberPausePlugin extends BasePlugin
{
    /**
     * Handles incoming messages for pause/resume commands.
     *
     * @param array $message The incoming message.
     * @return string|null The response message or null.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = strtolower(trim($message['text']));
        $sender = $message['sender'];

        // 1. Check for "stop" or "stop <minutes>"
        if (preg_match('/^stop(\s+(\d+))?$/', $text, $matches)) {
            $duration = isset($matches[2]) ? (int)$matches[2] : 525600; // Default to 1 year

            if ($duration <= 0) {
                return "Invalid duration. Please specify a number of minutes greater than 0.";
            }

            if ($this->dbConnect()) {
                $deleteQuery = "DELETE FROM number_pause WHERE phoneNumber = ?";
                $deleteStmt = $this->dbh->prepare($deleteQuery);
                $deleteStmt->bind_param('s', $sender);
                $deleteStmt->execute();

                $query = "INSERT INTO number_pause (phoneNumber, startTime, duration, isPaused) VALUES (?, NOW(), ?, 1)";
                $stmt = $this->dbh->prepare($query);
                $stmt->bind_param('si', $sender, $duration);

                if ($stmt->execute()) {
                    $this->log("Outgoing messages paused for {$sender} for {$duration} minutes.");
                    $durationText = ($duration >= 525600) ? "indefinitely" : "for {$duration} minutes";
                    $response = "Alerts have been paused {$durationText} for this number. Reply 'go' to resume.";
                } else {
                    $this->log("Failed to pause outgoing messages for {$sender}. Error: " . $stmt->error);
                    $response = "Error: Could not pause alerts for your number.";
                }
                $this->dbClose();
                return $response;
            }
            return "Error: Could not connect to the database.";
        }

        // 2. Check for "go"
        if ($text === 'go') {
            if ($this->dbConnect()) {
                $query = "DELETE FROM number_pause WHERE phoneNumber = ?";
                $stmt = $this->dbh->prepare($query);
                $stmt->bind_param('s', $sender);

                if ($stmt->execute()) {
                    $this->log("Outgoing messages resumed for {$sender}.");
                    $response = "Alerts have been resumed for this number.";
                } else {
                    $this->log("Failed to resume outgoing messages for {$sender}. Error: " . $stmt->error);
                    $response = "Error: Could not resume alerts for your number.";
                }
                $this->dbClose();
                return $response;
            }
            return "Error: Could not connect to the database.";
        }

        return null;
    }

    /**
     * Blocks outgoing messages if the destination number is paused.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array|null The message data if sending is allowed, or null to block.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        $recipient = $messageData['number'];

        if ($this->isPaused($recipient)) {
            $this->log("Suppressing outgoing message to {$recipient} due to user-requested pause.");
            return null;
        }

        return $messageData;
    }

    /**
     * Checks if a specific phone number is paused.
     * @param string $phoneNumber
     * @return bool
     */
    private function isPaused(string $phoneNumber): bool
    {
        if ($this->dbConnect()) {
            $query = "SELECT isPaused FROM number_pause WHERE phoneNumber = ? AND NOW() <= DATE_ADD(startTime, INTERVAL duration MINUTE) LIMIT 1";
            $stmt = $this->dbh->prepare($query);
            $stmt->bind_param('s', $phoneNumber);

            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $this->dbClose();
                    return (bool)$row['isPaused'];
                }
            } else {
                $this->log("Failed to query number_pause for {$phoneNumber}. Error: " . $stmt->error);
            }
            $this->dbClose();
        }

        return false;
    }
}
