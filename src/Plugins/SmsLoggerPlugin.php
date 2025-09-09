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
     * Logs the outgoing message to the database.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array The unmodified message data.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        if ($this->dbConnect()) {
            $query = "INSERT INTO smsLog (sendTo, dateTime, message) VALUES (?, NOW(), ?)";
            $stmt = $this->dbh->prepare($query);

            if ($stmt) {
                $stmt->bind_param('ss', $messageData['number'], $messageData['message']);
                if ($stmt->execute()) {
                    $this->log("Successfully logged outgoing message to {$messageData['number']}.");
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
