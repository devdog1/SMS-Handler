<?php

namespace SmsDaemon\Plugins;

use \DateTime;

/**
 * Class HistoryPlugin
 *
 * Handles requests from users to see their last few messages.
 * Triggered by the keyword "history".
 */
class HistoryPlugin extends BasePlugin
{
    /**
     * @param array $message The incoming message.
     * @return string|null The response message or null.
     */
    public function handleIncoming(array $message): ?string
    {
        $text = $message['text'];
        $sender = $message['sender'];

        // Trigger on the keyword "history", case-insensitive.
        if (preg_match("/^history/i", $text)) {
            $this->log("Handling history request from {$sender}.");

            if ($this->dbConnect()) {
                // This query assumes the smsLog table contains a 'message' column with the sent SMS text
                // and a 'dateTime' column with the timestamp.
                $query = "SELECT message, dateTime FROM smsLog WHERE sendTo = ? ORDER BY dateTime DESC LIMIT 5";

                $stmt = $this->dbh->prepare($query);
                $stmt->bind_param('s', $sender);

                $response = "An error occurred while retrieving your history."; // Default error message
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $messages = $result->fetch_all(MYSQLI_ASSOC);

                    if (count($messages) > 0) {
                        $this->log("Found " . count($messages) . " history entries for {$sender}.");
                        $response_lines = ["Last " . count($messages) . " messages:"];
                        foreach ($messages as $msg) {
                            $date = new DateTime($msg['dateTime']);
                            // Format to something like "Mon 09:59 - Your message here"
                            $response_lines[] = $date->format('D H:i') . ' - ' . $msg['message'];
                        }
                        $response = implode("\n", $response_lines);
                    } else {
                        $this->log("No history found for {$sender}.");
                        $response = "No recent message history found for your number.";
                    }
                } else {
                    $this->log("History query failed for {$sender}. Error: " . $stmt->error);
                    $response = "There was an error retrieving your message history.";
                }

                $this->dbClose();
                return $response;
            } else {
                $this->log("Database connection failed for History request.");
                return "Error: Could not connect to the database to process your request.";
            }
        }

        return null; // Message not handled by this plugin.
    }
}
