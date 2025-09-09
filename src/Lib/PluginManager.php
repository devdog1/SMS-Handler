<?php

namespace SmsDaemon\Lib;

use SmsDaemon\Plugins\IPlugin;
use \ReflectionClass;

/**
 * Class PluginManager
 *
 * Discovers, loads, and manages plugins. It passes incoming messages
 * to the loaded plugins for processing.
 */
class PluginManager
{
    private $plugins = [];
    private $dbConfig;
    private $debug;

    /**
     * PluginManager constructor.
     * @param string $pluginDir The directory to scan for plugins.
     * @param array $dbConfig The database configuration for plugins.
     * @param bool $debug Flag to enable debug logging.
     */
    public function __construct(string $pluginDir, array $dbConfig, bool $debug = false)
    {
        $this->dbConfig = $dbConfig;
        $this->debug = $debug;
        $this->loadPlugins($pluginDir);
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            error_log("SMS-DAEMON-PLUGINMAN: " . $message);
        }
    }

    /**
     * Scans a directory for .php files, includes them, and instantiates them as plugins.
     * @param string $pluginDir The directory containing plugin files.
     */
    private function loadPlugins(string $pluginDir): void
    {
        $this->log("Loading plugins from: {$pluginDir}");
        if (!is_dir($pluginDir)) {
            $this->log("Plugin directory not found.");
            return;
        }

        $pluginFiles = glob($pluginDir . '/*.php');

        foreach ($pluginFiles as $file) {
            // Skip the interface and abstract base class
            if (strpos($file, 'IPlugin.php') !== false || strpos($file, 'BasePlugin.php') !== false) {
                continue;
            }

            require_once $file;
            $className = 'SmsDaemon\\Plugins\\' . basename($file, '.php');

            if (class_exists($className)) {
                $reflection = new ReflectionClass($className);
                if (!$reflection->isInstantiable()) {
                    continue;
                }

                $plugin = new $className($this->dbConfig, $this->debug);

                if ($plugin instanceof IPlugin) {
                    $this->plugins[] = $plugin;
                    $this->log("Successfully loaded plugin: {$className}");
                }
            }
        }
    }

    /**
     * Passes a message to all loaded plugins until one handles it.
     * @param array $message The message to be handled.
     * @return string|null The response from the plugin, or null if no plugin handled it.
     */
    public function handleMessage(array $message): ?string
    {
        $this->log("Passing message from {$message['sender']} to " . count($this->plugins) . " plugins.");
        foreach ($this->plugins as $plugin) {
            $response = $plugin->handle($message);
            if ($response !== null) {
                $this->log("Message handled by " . get_class($plugin));
                return $response;
            }
        }
        $this->log("No plugin handled the message.");
        return null;
    }
}
