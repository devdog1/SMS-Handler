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
    protected $dbConfig;
    protected $debug;
    protected $dbh;

    /**
     * BasePlugin constructor.
     * @param array $dbConfig The database configuration array.
     * @param bool $debug Flag to enable or disable debug logging.
     */
    public function __construct(array $dbConfig, bool $debug)
    {
        $this->dbConfig = $dbConfig;
        $this->debug = $debug;
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
     * The main handler method that must be implemented by concrete plugins.
     */
    abstract public function handle(array $message): ?string;

    /**
     * Destructor to ensure the database connection is closed.
     */
    public function __destruct()
    {
        $this->dbClose();
    }
}
