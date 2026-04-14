<?php

/**
 * Status page for SMS Daemon.
 */

define('ROOT_DIR', dirname(__DIR__));

// --- Configuration Loading ---
$config_file = ROOT_DIR . '/config.php';
$local_config_file = ROOT_DIR . '/config.local.php';

if (!file_exists($config_file)) {
    die("FATAL: Main configuration file not found.\n");
}
$config = require $config_file;

if (file_exists($local_config_file)) {
    $local_config = require $local_config_file;
    $config = array_replace_recursive($config, $local_config);
}

// --- Helper Functions ---
function get_spool_count(string $dir): int {
    if (!is_dir($dir)) return 0;
    $files = scandir($dir);
    return count(array_filter($files, function($f) use ($dir) {
        return is_file($dir . '/' . $f);
    }));
}

function get_last_lines(string $file, int $lines = 20): string {
    if (!is_file($file)) return "Log file not found.";
    $data = shell_exec("tail -n $lines " . escapeshellarg($file));
    return $data ?: "Empty log.";
}

// --- Data Gathering ---
$heartbeatFile = $config['daemon']['heartbeat_file'] ?? '';
$heartbeat = null;
$isActive = false;
if (file_exists($heartbeatFile)) {
    $heartbeat = json_decode(file_get_contents($heartbeatFile), true);
    if ($heartbeat && (time() - $heartbeat['timestamp'] < 60)) { // Active if updated in last 60s
        $isActive = true;
    }
}

$spoolStats = [
    'outgoing' => get_spool_count($config['spool']['outgoing']),
    'failed' => get_spool_count($config['spool']['failed']),
    'incoming' => get_spool_count($config['spool']['incoming'] ?? ''),
];

$recentLogs = [];
$dbConfig = $config['database'];
$dbh = @new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
if (!$dbh->connect_error) {
    $result = $dbh->query("SELECT * FROM smsLog ORDER BY dateTime DESC LIMIT 20");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recentLogs[] = $row;
        }
    }
    $dbh->close();
}

$daemonLog = get_last_lines($config['daemon']['log_file'] ?? '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Daemon Status</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; max-width: 1000px; margin: 0 auto; padding: 20px; background: #f4f4f9; }
        h1, h2 { color: #2c3e50; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
        .status-box { padding: 15px; border-radius: 5px; margin-bottom: 20px; font-weight: bold; }
        .active { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .inactive { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .card { background: #fff; padding: 15px; border-radius: 5px; border: 1px solid #ddd; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card h3 { margin-top: 0; font-size: 1.1rem; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .stat-value { font-size: 2rem; font-weight: bold; color: #3498db; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 10px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        tr:hover { background: #f1f1f1; }
        pre { background: #2c3e50; color: #ecf0f1; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 0.85rem; }
        .status-badge { padding: 4px 8px; border-radius: 3px; font-size: 0.75rem; text-transform: uppercase; }
        .badge-sent { background: #28a745; color: #fff; }
        .badge-failed { background: #dc3545; color: #fff; }
        .badge-withheld { background: #ffc107; color: #212529; }
    </style>
    <meta http-equiv="refresh" content="30">
</head>
<body>
    <h1>SMS Daemon Status</h1>

    <div class="status-box <?= $isActive ? 'active' : 'inactive' ?>">
        Daemon Status: <?= $isActive ? 'ACTIVE' : 'INACTIVE / STALLED' ?>
        <?php if ($heartbeat): ?>
            <span style="font-weight: normal; font-size: 0.9rem; float: right;">Last Heartbeat: <?= date('Y-m-d H:i:s', $heartbeat['timestamp']) ?></span>
        <?php endif; ?>
    </div>

    <div class="grid">
        <div class="card">
            <h3>Backend</h3>
            <div class="stat-value"><?= htmlspecialchars(strtoupper($config['backend'] ?? 'UNKNOWN')) ?></div>
            <p><small>Interval: <?= $config['daemon']['loop_interval'] ?>s</small></p>
        </div>
        <div class="card">
            <h3>Spool: Outgoing</h3>
            <div class="stat-value"><?= $spoolStats['outgoing'] ?></div>
        </div>
        <div class="card">
            <h3>Spool: Incoming</h3>
            <div class="stat-value"><?= $spoolStats['incoming'] ?></div>
        </div>
        <div class="card">
            <h3>Spool: Failed</h3>
            <div class="stat-value"><?= $spoolStats['failed'] ?></div>
        </div>
    </div>

    <?php if ($heartbeat && isset($heartbeat['status'])): ?>
    <h2>Current Cycle Info</h2>
    <div class="grid">
        <div class="card">
            <h3>Sent (this cycle)</h3>
            <div class="stat-value"><?= $heartbeat['status']['messages_sent'] ?></div>
        </div>
        <div class="card">
            <h3>Received (this cycle)</h3>
            <div class="stat-value"><?= $heartbeat['status']['messages_received'] ?></div>
        </div>
        <div class="card">
            <h3>Cycle Start</h3>
            <p><?= $heartbeat['status']['last_cycle_start'] ?></p>
        </div>
    </div>
    <?php if (!empty($heartbeat['status']['errors'])): ?>
        <div class="card" style="border-left: 5px solid #dc3545;">
            <h3 style="color: #dc3545;">Recent Errors</h3>
            <ul>
                <?php foreach ($heartbeat['status']['errors'] as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <h2>Recent Activity (DB)</h2>
    <table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Recipient</th>
                <th>Message</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td><small><?= $log['dateTime'] ?></small></td>
                    <td><?= htmlspecialchars($log['sendTo']) ?></td>
                    <td><?= htmlspecialchars($log['message']) ?></td>
                    <td>
                        <span class="status-badge badge-<?= $log['status'] ?>">
                            <?= htmlspecialchars($log['status']) ?>
                        </span>
                        <?php if ($log['withhold_reason']): ?>
                            <br><small><em><?= htmlspecialchars($log['withhold_reason']) ?></em></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentLogs)): ?>
                <tr><td colspan="4">No recent activity found in database.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>System Log (Last 20 lines)</h2>
    <pre><?= htmlspecialchars($daemonLog) ?></pre>

</body>
</html>
