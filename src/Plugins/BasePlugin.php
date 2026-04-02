<?php

namespace SmsDaemon\Plugins;

use \mysqli;
use \ReflectionClass;

/**
 * Class BasePlugin
 *
 * An abstract base class for plugins that provides common functionality
 * such as database connectivity and logging.
 */
abstract class BasePlugin implements IPlugin
{
    protected $config;
    protected $dbConfig;
    protected $debug;
    protected $dbh;

    /**
     * BasePlugin constructor.
     * @param array $config The main application configuration array.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->dbConfig = $config['database'] ?? [];
        $this->debug = $config['debug'] ?? false;
    }

    /**
     * Logs a message if debug mode is enabled.
     * The message is automatically prefixed with the plugin's class name.
     * @param string $message The message to log.
     */
    protected function log(string $message): void
    {
        if ($this->debug) {
            $pluginName = (new ReflectionClass($this))->getShortName();
            error_log("SMS-DAEMON-PLUGIN-{$pluginName}: " . $message);
        }
    }

    /**
     * Establishes a database connection using mysqli.
     * @return bool True on success, false on failure.
     */
    protected function dbConnect(): bool
    {
        if ($this->dbh) {
            return true;
        }

        // Suppress default warning, we handle the error manually.
        $this->dbh = @new mysqli(
            $this->dbConfig['host'],
            $this->dbConfig['user'],
            $this->dbConfig['pass'],
            $this->dbConfig['name']
        );

        if ($this->dbh->connect_error) {
            $this->log("Database connection failed: " . $this->dbh->connect_error);
            $this->dbh = null;
            return false;
        }
        return true;
    }

    /**
     * Closes the active database connection.
     */
    protected function dbClose(): void
    {
        if ($this->dbh) {
            $this->dbh->close();
            $this->dbh = null;
        }
    }

    /**
     * Sanitizes a phone number to exactly 10 digits.
     * @param string $number
     * @return string
     */
    protected function sanitizePhoneNumber(string $number): string
    {
        $clean = preg_replace('/\D/', '', $number);
        if (strlen($clean) > 10) {
            return substr($clean, -10);
        }
        return $clean;
    }

    /**
     * The main handler method that must be implemented by concrete plugins.
     */
    abstract public function handleIncoming(array $message): ?string;

    /**
     * Default implementation for handling outgoing messages.
     * Plugins can override this method to modify or cancel outgoing messages.
     * By default, it does nothing and allows the message to be sent as-is.
     *
     * @param array $messageData The data for the outgoing message.
     * @return array|null The (potentially modified) message data.
     */
    public function handleOutgoing(array $messageData): ?array
    {
        return $messageData;
    }

    /**
     * Destructor to ensure the database connection is closed.
     */
    public function __destruct()
    {
        $this->dbClose();
    }
}
