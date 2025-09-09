<?php

namespace SmsDaemon\Plugins;

/**
 * Class BulkAckPlugin
 *
 * Handles SMS messages that trigger a bulk acknowledgement of recent events.
 * It looks for keywords like "ok", "go", or "fuck" at the start of a message.
 */
class BulkAckPlugin extends BasePlugin
{
    /**
     * @param array $message The incoming message.
     * @return string|null The response message or null.
     */
    public function handle(array $message): ?string
    {
        $text = $message['text'];
        $sender = $message['sender'];

        // Use a case-insensitive regex for the trigger words.
        if (preg_match("/^(fuck|go|ok)/i", $text)) {
            $this->log("Handling Bulk Ack message from {$sender}.");

            $ackTime = 240; // Ack duration in minutes (4 hours)
            $timeInterval = "60 MINUTE"; // SQL interval string for how far back to look for alerts

            if ($this->dbConnect()) {
                // This query structure is ported from the original script.
                // It assumes the existence of 'wcg.acknowledgements' and 'smsLog' tables.
                $query = "
                    INSERT INTO wcg.acknowledgements (eventId, sendTo, startTime, duration)
                    SELECT smsLog.eventId, smsLog.sendTo, NOW(), ?
                    FROM smsLog
                    WHERE smsLog.dateTime > (NOW() - INTERVAL 1 HOUR) AND smsLog.sendTo = ?";

                $stmt = $this->dbh->prepare($query);
                $stmt->bind_param('is', $ackTime, $sender);

                if ($stmt->execute()) {
                    $affectedRows = $stmt->affected_rows;
                    if ($affectedRows < 0) $affectedRows = 0; // In case of -1 from DB driver
                    $this->log("Bulk acknowledgement processed for {$affectedRows} triggers.");
                    $response = "Bulk acknowledgement received for {$affectedRows} triggers. You will not be notified for {$ackTime} minutes for these triggers.";
                } else {
                    $this->log("Bulk acknowledgement query failed. Error: " . $stmt->error);
                    $response = "There was an error processing your bulk acknowledgement.";
                }

                $this->dbClose();
                return $response;
            } else {
                $this->log("Database connection failed for Bulk Ack.");
                return "Error: Could not connect to the database to process your request.";
            }
        }

        return null; // Message not handled by this plugin.
    }
}
