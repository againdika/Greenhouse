<?php
// receive_csv.php - Place on remote server to receive CSV uploads from greenhouse server
// Location: /var/www/html/greenhouse_uploads/receive_csv.php

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
    if ($apiKey !== API_SECRET_KEY && $apiKey !== 'xxxxxxxxxx') { // also accept old key for compatibility
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
$latest = null;
if (!empty($files)) {
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $latest = $files[0];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Greenhouse Data Receiver</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f7f5; padding: 24px; margin: 0; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: #1f5e45; color: white; padding: 24px; border-radius: 16px; margin-bottom: 24px; }
        .header h1 { margin: 0 0 8px 0; font-size: 1.5rem; }
        .header p { margin: 0; opacity: 0.85; }
        .info { background: white; border: 1px solid #e0ebe5; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .info h3 { margin: 0 0 16px 0; color: #1f5e45; }
        .file-info { background: #f0f6f2; padding: 16px; border-radius: 8px; margin: 12px 0; font-family: monospace; word-break: break-all; }
        .badge { display: inline-block; background: #2d6a4f; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; margin-top: 12px; }
        .status-ok { color: #2d6a4f; font-weight: bold; }
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
        <p>Total files stored: <strong><?= count($files) ?></strong></p>
        <?php if ($latest): ?>
        <div class="file-info">
            <strong>Latest File:</strong><br>
            <?= basename($latest) ?><br>
            Size: <?= round(filesize($latest)/1024,2) ?> KB<br>
            Received: <?= date('Y-m-d H:i:s', filemtime($latest)) ?>
        </div>
        <?php else: ?>
        <p>No files received yet. Waiting for upload from greenhouse server.</p>
        <?php endif; ?>
        <span class="badge">Auto-Removal Active</span>
        <p style="margin-top: 16px; font-size: 14px; color: #6f8f7e;">
            When a new CSV file is uploaded, all previous files are automatically deleted.
        </p>
    </div>
</div>
</body>
</html>