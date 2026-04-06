<?php

namespace SmsDaemon\Plugins;

/**
 * Class SmsLoggerPlugin
 *
 * Logs all successfully sent messages to the `smsLog` database table.
 * This plugin hooks into the outgoing message pipeline.
 */
class SmsLoggerPlugin extends BasePlugin
{
    /**
     * This plugin does not handle incoming messages.
     */
    public function handleIncoming(array $message): ?string
    {
        return null;
    }

    /**
     * Logs the outgoing message to the database, extracting the eventId if present.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array The unmodified message data.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        // Try to find an eventId in the message body
        $eventId = null;
        if (preg_match('/E:(\d+)/', $messageData['message'], $matches)) {
            $eventId = (int)$matches[1];
        }

        if ($this->dbConnect()) {
            $query = "INSERT INTO smsLog (sendTo, dateTime, message, eventId, status, withhold_reason) VALUES (?, NOW(), ?, ?, ?, ?)";
            $stmt = $this->dbh->prepare($query);

            if ($stmt) {
                $status = $messageData['status'] ?? 'sent';
                $withholdReason = $messageData['withhold_reason'] ?? null;
                // Bind params: sendTo (s), message (s), eventId (i), status (s), withhold_reason (s)
                $stmt->bind_param('ssiss', $messageData['number'], $messageData['message'], $eventId, $status, $withholdReason);
                if ($stmt->execute()) {
                    $this->log("Successfully logged outgoing message to {$messageData['number']} (status: {$status}, reason: " . ($withholdReason ?? 'None') . ") with eventId: " . ($eventId ?? 'NULL'));
                } else {
                    $this->log("Failed to log outgoing message. DB Error: " . $stmt->error);
                }
            } else {
                $this->log("Failed to prepare statement for logging. DB Error: " . $this->dbh->error);
            }
            $this->dbClose();
        } else {
            $this->log("Database connection failed for SMS Logger.");
        }

        // Always return the message data so that sending is not interrupted
        return $messageData;
    }
}
