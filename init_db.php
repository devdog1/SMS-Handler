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

$query = "CREATE TABLE IF NOT EXISTS number_pause (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phoneNumber VARCHAR(20) NOT NULL,
    startTime DATETIME NOT NULL,
    duration INT NOT NULL DEFAULT 525600, -- Default to 1 year in minutes
    isPaused TINYINT(1) NOT NULL DEFAULT 0,
    INDEX (phoneNumber)
)";

if ($dbh->query($query) === TRUE) {
    echo "Table number_pause created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $dbh->error . "\n";
}

$query = "CREATE TABLE IF NOT EXISTS acknowledgements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sendTo VARCHAR(20) NOT NULL,
    eventId BIGINT NOT NULL,
    startTime DATETIME NOT NULL,
    duration INT NOT NULL,
    INDEX (sendTo, eventId)
)";

if ($dbh->query($query) === TRUE) {
    echo "Table acknowledgements created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $dbh->error . "\n";
}

$query = "CREATE TABLE IF NOT EXISTS smsLog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sendTo VARCHAR(20) NOT NULL,
    dateTime DATETIME NOT NULL,
    message TEXT NOT NULL,
    eventId BIGINT DEFAULT NULL,
    INDEX (sendTo),
    INDEX (eventId)
)";

if ($dbh->query($query) === TRUE) {
    echo "Table smsLog created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $dbh->error . "\n";
}

$dbh->close();
