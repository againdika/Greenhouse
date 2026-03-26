<?php
// export_to_remote.php - Simple export interface
require_once dirname(__FILE__) . '/upload_config.php';

$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    require_once dirname(__DIR__) . '/auth.php';
    requireLogin();
}

$dateFrom = $_GET['from'] ?? $_POST['from'] ?? null;
$dateTo = $_GET['to'] ?? $_POST['to'] ?? null;

$preset = $_GET['preset'] ?? $_POST['preset'] ?? null;
switch ($preset) {
    case '24h':
        $dateFrom = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $dateTo = date('Y-m-d H:i:s');
        break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        $dateTo = date('Y-m-d');
        break;
}

if (isset($_GET['download'])) {
    $csvFile = generateSensorCSV($dateFrom, $dateTo);
    if ($csvFile && file_exists($csvFile)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="greenhouse_export_' . date('Y-m-d') . '.csv"');
        readfile($csvFile);
        exit;
    } else {
        die('No data available');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['upload'])) {
    $result = uploadLatestData($dateFrom, $dateTo);
    if ($isCLI) {
        echo $result['success'] ? "SUCCESS\n" : "ERROR\n";
    } else {
        echo json_encode($result);
    }
    exit;
}

if (!$isCLI) {
    echo sharedHead('Export to Remote');
    echo sharedCSS();
    ?>
    <style>
    .export-container { max-width: 800px; margin: 0 auto; }
    .option-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 24px; margin-bottom: 20px; }
    .preset-buttons { display: flex; gap: 12px; flex-wrap: wrap; margin: 20px 0; }
    .preset-btn { padding: 10px 20px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); text-decoration: none; color: var(--ink); display: inline-block; }
    .preset-btn:hover { background: var(--green-bg); border-color: var(--green); text-decoration: none; }
    </style>
    </head><body>
    <?= sharedNav('export') ?>
    <div class="page">
        <div class="export-container">
            <div class="sec-head">
                <span class="sec-title">Export to Remote Server</span>
                <span class="sec-meta">Upload sensor data to <?= REMOTE_HOST ?></span>
            </div>
            
            <div class="option-card">
                <h3>Quick Export</h3>
                <div class="preset-buttons">
                    <a href="?preset=24h&upload=1" class="preset-btn">Upload Last 24 Hours</a>
                    <a href="?preset=week&upload=1" class="preset-btn">Upload Last Week</a>
                </div>
            </div>
            
            <div class="option-card">
                <h3>Download CSV</h3>
                <div class="preset-buttons">
                    <a href="?preset=24h&download=1" class="preset-btn">Download Last 24 Hours</a>
                    <a href="?preset=week&download=1" class="preset-btn">Download Last Week</a>
                </div>
            </div>
            
            <div class="option-card">
                <h3>Custom Date Range</h3>
                <form method="GET">
                    <div class="form-grid" style="margin-bottom: 20px;">
                        <div class="field">
                            <label>From Date</label>
                            <input type="date" name="from">
                        </div>
                        <div class="field">
                            <label>To Date</label>
                            <input type="date" name="to">
                        </div>
                    </div>
                    <div class="button-group">
                        <button type="submit" name="upload" value="1" class="btn btn-primary">Upload to Remote</button>
                        <button type="submit" name="download" value="1" class="btn btn-outline">Download CSV</button>
                    </div>
                </form>
            </div>
            
            <div class="option-card">
                <h3>Server Information</h3>
                <div class="kv-list">
                    <span class="kv-key">Remote Host</span><span class="kv-val"><?= REMOTE_HOST ?></span>
                    <span class="kv-key">Remote Path</span><span class="kv-val"><?= REMOTE_PATH ?></span>
                    <span class="kv-key">Upload Interval</span><span class="kv-val">Every 60 minutes (via Windows Task Scheduler)</span>
                    <span class="kv-key">db.php Location</span><span class="kv-val">C:\wamp64\www\greenhouse\db.php</span>
                </div>
            </div>
        </div>
    </div>
    </body></html>
    <?php
}
?>