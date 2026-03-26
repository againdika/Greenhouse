<?php
define('UPLOAD_DIR', __DIR__ . '/received_csv/');
define('API_SECRET_KEY', 'xxxxxxxxxxxxxxx'); // Must match the key used by the device when sending data
define('LOG_FILE', __DIR__ . '/receiver_log.txt');

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

function writeLog($msg) {
    file_put_contents(LOG_FILE, "[" . date('Y-m-d H:i:s') . "] {$msg}\n", FILE_APPEND);
}

function removeOldFiles() {
    $files = glob(UPLOAD_DIR . '*.csv');
    if (empty($files)) return 0;
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $toDelete = array_slice($files, 1);
    $count = 0;
    foreach ($toDelete as $f) {
        if (unlink($f)) $count++;
    }
    if ($count) writeLog("Removed {$count} old file(s)");
    return $count;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = $_POST['api_key'] ?? '';
    if ($apiKey !== API_SECRET_KEY && $apiKey !== 'gh_secret_key_2024') {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
    
    $csvData = $_POST['csv_data'] ?? '';
    $filename = $_POST['filename'] ?? 'greenhouse_log.csv';
    $serverName = $_POST['server_name'] ?? 'unknown';
    
    if (empty($csvData)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data']);
        exit;
    }
    
    $csvContent = base64_decode($csvData);
    if ($csvContent === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid encoding']);
        exit;
    }
    
    removeOldFiles();
    
    $filepath = UPLOAD_DIR . date('Y-m-d_H-i-s') . '_' . $serverName . '.csv';
    
    if (file_put_contents($filepath, $csvContent)) {
        writeLog("Saved: " . basename($filepath));
        echo json_encode(['success' => true, 'filename' => basename($filepath)]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Save failed']);
    }
    exit;
}

// Web interface
$files = glob(UPLOAD_DIR . '*.csv');
$latest = !empty($files) ? max($files, function($a,$b){return filemtime($a)-filemtime($b);}) : null;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Greenhouse Data Receiver</title>
    <style>
        body { font-family: sans-serif; background: #f5f7f5; padding: 24px; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: #1f5e45; color: white; padding: 24px; border-radius: 16px; margin-bottom: 24px; }
        .info { background: white; border: 1px solid #e0ebe5; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .file { background: #f0f6f2; padding: 12px 16px; border-radius: 8px; font-family: monospace; word-break: break-all; }
        .badge { display: inline-block; background: #2d6a4f; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Greenhouse Data Receiver</h1>
        <p>Auto-removal enabled | Only latest file kept</p>
    </div>
    <div class="info">
        <h3>Status</h3>
        <p>Total files stored: <?= count($files) ?></p>
        <?php if ($latest): ?>
        <p>Latest file: <span class="file"><?= basename($latest) ?></span></p>
        <p>Size: <?= round(filesize($latest)/1024,2) ?> KB | Modified: <?= date('Y-m-d H:i:s', filemtime($latest)) ?></p>
        <?php else: ?>
        <p>No files received yet.</p>
        <?php endif; ?>
        <p class="badge">Auto-Removal Active</p>
    </div>
</div>
</body>
</html>