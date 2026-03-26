<?php
// ══════════════════════════════════════════════════════════════════════
//  remote_receiver.php  —  Modified with auto-removal of old files
// ══════════════════════════════════════════════════════════════════════

define('UPLOAD_DIR', __DIR__ . '/received_csv/');
define('API_SECRET_KEY', 'xxxxxxxxxxxxxxx'); // Must match the key used by the device when sending data
define('LOG_FILE', __DIR__ . '/receiver_log.txt');
define('KEEP_ONLY_LATEST', true);  // NEW: Only keep the latest file

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

function writeReceiverLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

// NEW: Function to remove old CSV files
function removeOldCSVFiles($keepLatestOnly = true) {
    $files = glob(UPLOAD_DIR . '*.csv');
    if (empty($files)) return 0;
    
    if ($keepLatestOnly) {
        // Sort by modification time, newest first
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Keep only the first file (latest), delete the rest
        $toDelete = array_slice($files, 1);
        $deletedCount = 0;
        foreach ($toDelete as $file) {
            if (unlink($file)) {
                $deletedCount++;
                writeReceiverLog("Removed old file: " . basename($file));
            }
        }
        return $deletedCount;
    }
    return 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $apiKey = $_POST['api_key'] ?? '';
    if ($apiKey !== API_SECRET_KEY && $apiKey !== 'xxxxxxxxxxxx') { // also accept old key for compatibility
        http_response_code(403);
        echo json_encode(['error' => 'Invalid API key']);
        writeReceiverLog("Rejected: Invalid API key from " . $_SERVER['REMOTE_ADDR']);
        exit;
    }
    
    $csvData = $_POST['csv_data'] ?? '';
    $filename = $_POST['filename'] ?? 'greenhouse_log_' . date('Y-m-d_H-i-s') . '.csv';
    $serverName = $_POST['server_name'] ?? 'unknown';
    $timestamp = $_POST['timestamp'] ?? date('Y-m-d H:i:s');
    $replaceExisting = $_POST['replace_existing'] ?? 'true';
    
    if (empty($csvData)) {
        http_response_code(400);
        echo json_encode(['error' => 'No CSV data received']);
        writeReceiverLog("Error: No CSV data from {$serverName}");
        exit;
    }
    
    $csvContent = base64_decode($csvData);
    
    if ($csvContent === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSV data encoding']);
        writeReceiverLog("Error: Invalid base64 data from {$serverName}");
        exit;
    }
    
    // NEW: Remove old files before saving new one
    $deletedCount = 0;
    if ($replaceExisting === 'true') {
        $deletedCount = removeOldCSVFiles(KEEP_ONLY_LATEST);
        if ($deletedCount > 0) {
            writeReceiverLog("Removed {$deletedCount} old CSV file(s) before saving new data");
        }
    }
    
    $uniqueFilename = date('Y-m-d_H-i-s') . '_' . $serverName . '_' . $filename;
    $filepath = UPLOAD_DIR . $uniqueFilename;
    
    if (file_put_contents($filepath, $csvContent)) {
        $fileSize = round(filesize($filepath) / 1024, 2);
        writeReceiverLog("Success: Received {$uniqueFilename} ({$fileSize} KB) from {$serverName} (deleted {$deletedCount} old files)");
        echo json_encode([
            'success' => true,
            'message' => 'CSV file received successfully',
            'filename' => $uniqueFilename,
            'size_kb' => $fileSize,
            'deleted_old' => $deletedCount
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save CSV file']);
        writeReceiverLog("Error: Failed to save file from {$serverName}");
    }
    
} else {
    // NEW: Show only the latest file on the web interface
    $files = glob(UPLOAD_DIR . '*.csv');
    $totalSize = 0;
    $latestFile = null;
    $latestTime = 0;
    
    foreach ($files as $file) {
        $totalSize += filesize($file);
        $mtime = filemtime($file);
        if ($mtime > $latestTime) {
            $latestTime = $mtime;
            $latestFile = $file;
        }
    }
    $totalSizeKB = round($totalSize / 1024, 2);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Greenhouse Data Receiver</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f5f7f5;
                color: #1a3b2f;
                padding: 24px;
            }
            .container { max-width: 1000px; margin: 0 auto; }
            .header {
                background: #1f5e45;
                color: white;
                padding: 28px 32px;
                border-radius: 20px;
                margin-bottom: 28px;
            }
            .header h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 8px; }
            .header p { opacity: 0.85; font-size: 0.9rem; }
            .stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 20px;
                margin-bottom: 28px;
            }
            .stat-card {
                background: white;
                border: 1px solid #e0ebe5;
                border-radius: 16px;
                padding: 20px;
                text-align: center;
            }
            .stat-number {
                font-size: 2rem;
                font-weight: 700;
                color: #1f5e45;
                line-height: 1;
                margin-bottom: 8px;
            }
            .stat-label {
                font-size: 0.75rem;
                color: #6f8f7e;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .current-file {
                background: white;
                border: 1px solid #e0ebe5;
                border-radius: 16px;
                padding: 24px;
                margin-bottom: 28px;
            }
            .current-file h3 {
                font-size: 1rem;
                font-weight: 600;
                color: #1f5e45;
                margin-bottom: 16px;
                padding-bottom: 12px;
                border-bottom: 1px solid #e0ebe5;
            }
            .file-info {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 16px;
                padding: 12px 0;
            }
            .file-name {
                font-family: monospace;
                font-size: 0.9rem;
                color: #2d6a4f;
                background: #f0f6f2;
                padding: 8px 16px;
                border-radius: 8px;
                word-break: break-all;
            }
            .file-meta {
                color: #6f8f7e;
                font-size: 0.85rem;
            }
            .download-link {
                background: #2d6a4f;
                color: white;
                padding: 8px 20px;
                border-radius: 30px;
                text-decoration: none;
                font-size: 0.85rem;
                font-weight: 500;
            }
            .download-link:hover {
                background: #1f5e45;
            }
            .info-note {
                background: #eef5f0;
                border-left: 4px solid #2d6a4f;
                padding: 14px 18px;
                border-radius: 12px;
                font-size: 0.85rem;
                color: #2d6a4f;
                margin-top: 20px;
            }
            .footer {
                margin-top: 32px;
                text-align: center;
                font-size: 0.75rem;
                color: #8aa99a;
                padding: 20px;
                border-top: 1px solid #e0ebe5;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Greenhouse Data Receiver</h1>
                <p>Auto-removal enabled | Only latest CSV file is retained</p>
            </div>
            
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?= count($files) ?></div>
                    <div class="stat-label">Total Files Stored</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $totalSizeKB ?> KB</div>
                    <div class="stat-label">Storage Used</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= date('Y-m-d H:i') ?></div>
                    <div class="stat-label">Last Update SLT</div>
                </div>
            </div>
            
            <div class="current-file">
                <h3>Latest CSV File</h3>
                <?php if ($latestFile): 
                    $latestName = basename($latestFile);
                    $latestSize = round(filesize($latestFile) / 1024, 2);
                    $latestDate = date('Y-m-d H:i:s', filemtime($latestFile));
                ?>
                <div class="file-info">
                    <div>
                        <div class="file-name"><?= htmlspecialchars($latestName) ?></div>
                        <div class="file-meta" style="margin-top: 8px;">Size: <?= $latestSize ?> KB | Received: <?= $latestDate ?></div>
                    </div>
                    <a href="?download=<?= urlencode($latestName) ?>" class="download-link">Download CSV</a>
                </div>
                <div class="info-note">
                    <strong>Auto-Removal Active</strong><br>
                    Only the most recent CSV file is kept. When a new file is uploaded, all previous files are automatically deleted.
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #8aa99a;">
                    No CSV files received yet. Waiting for upload from greenhouse server.
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (count($files) > 1): ?>
            <div style="background: #fff5e6; border: 1px solid #ffd9b3; border-radius: 12px; padding: 12px 16px; font-size: 0.8rem; color: #b45f06;">
                Note: Multiple files detected. The auto-cleanup will remove old files on next upload.
            </div>
            <?php endif; ?>
            
            <div class="footer">
                Greenhouse Monitoring System | CSV Auto-Upload | Files Auto-Rotate
            </div>
        </div>
    </body>
    </html>
    <?php
}

// NEW: Handle file download
if (isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = UPLOAD_DIR . $filename;
    
    if (file_exists($filepath) && is_file($filepath)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        http_response_code(404);
        echo "File not found";
        exit;
    }
}
?>