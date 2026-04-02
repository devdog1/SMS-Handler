<?php

require_once __DIR__ . '/src/Lib/Polyfills.php';
spl_autoload_register(function ($class) {
    $prefix = 'SmsDaemon\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

$config = require __DIR__ . '/src/config.php';
if (file_exists(__DIR__ . '/src/config.local.php')) {
    $local_config = require __DIR__ . '/src/config.local.php';
    $config = array_replace_recursive($config, $local_config);
}

$dbConfig = $config['database'];
// Using the same suppress pattern as BasePlugin
$dbh = @new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);

if ($dbh->connect_error) {
    die("Connection failed: " . $dbh->connect_error . "\n");
}

$query = "CREATE TABLE IF NOT EXISTS global_pause (
    id INT AUTO_INCREMENT PRIMARY KEY,
    startTime DATETIME NOT NULL,
    duration INT NOT NULL,
    pausedBy VARCHAR(20) NOT NULL
)";

if ($dbh->query($query) === TRUE) {
    echo "Table global_pause created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $dbh->error . "\n";
}

$dbh->close();
