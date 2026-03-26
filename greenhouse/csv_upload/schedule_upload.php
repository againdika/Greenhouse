<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes.php';
require_once dirname(__DIR__) . '/auth.php';
requireOwner();

$uploadResult = null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_now') {
    require_once dirname(__FILE__) . '/upload_config.php';
    
    $dateFrom = $_POST['date_from'] ?? null;
    $dateTo = $_POST['date_to'] ?? null;
    
    $uploadResult = uploadLatestData($dateFrom, $dateTo);
}

$logContent = '';
if (file_exists(LOG_FILE)) {
    $logContent = file_get_contents(LOG_FILE);
    $logLines = explode("\n", $logContent);
    $logLines = array_slice($logLines, -100);
    $logContent = implode("\n", $logLines);
}

$csvCount = 0;
if (is_dir(CSV_EXPORT_DIR)) {
    $csvFiles = glob(CSV_EXPORT_DIR . '*.csv');
    $csvCount = count($csvFiles);
}

echo sharedHead('CSV Upload Scheduler');
echo sharedCSS();
?>
<style>
.upload-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 28px;
}
.stat-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    text-align: center;
}
.stat-number {
    font-size: 2.2rem;
    font-weight: 700;
    color: var(--green);
    line-height: 1;
    margin-bottom: 8px;
}
.stat-label {
    font-size: 0.85rem;
    color: var(--ink-3);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.upload-panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 28px;
    margin-bottom: 28px;
}
.log-panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}
.log-header {
    background: #f7f8fa;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    font-weight: 600;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.log-content {
    padding: 20px;
    max-height: 400px;
    overflow-y: auto;
    font-family: monospace;
    font-size: 0.85rem;
    background: #1a1a2e;
    color: #aaffdd;
    white-space: pre-wrap;
}
.result-message {
    margin-top: 16px;
    padding: 12px 16px;
    border-radius: var(--radius);
}
.result-success {
    background: var(--green-bg);
    border: 1px solid var(--green-bdr);
    color: var(--green-txt);
}
.result-error {
    background: var(--red-bg);
    border: 1px solid var(--red-bdr);
    color: var(--red-txt);
}
.info-box {
    background: var(--blue-bg);
    border-left: 4px solid var(--blue);
    padding: 14px 18px;
    border-radius: 12px;
    margin-top: 16px;
    font-size: 0.85rem;
}
.task-scheduler {
    background: #2d3748;
    color: #e2e8f0;
    padding: 16px;
    border-radius: 8px;
    margin-top: 16px;
    font-family: monospace;
    font-size: 0.85rem;
}
.task-scheduler code {
    background: #1a1a2e;
    padding: 2px 6px;
    border-radius: 4px;
}
</style>
</head><body>
<?= sharedNav('admin') ?>
<div class="page">

<div class="sec-head">
    <span class="sec-title">CSV Auto-Upload to Remote Server</span>
    <span class="sec-meta">Uploads every 60 minutes | Only latest file kept</span>
</div>

<?php if ($uploadResult): ?>
<div class="result-message <?= $uploadResult['success'] ? 'result-success' : 'result-error' ?>">
    <strong><?= $uploadResult['success'] ? 'Upload Successful' : 'Upload Failed' ?></strong><br>
    <?= htmlspecialchars($uploadResult['message']) ?>
</div>
<?php endif; ?>

<div class="upload-stats">
    <div class="stat-box">
        <div class="stat-number"><?= $csvCount ?></div>
        <div class="stat-label">Local CSV Files</div>
    </div>
    <div class="stat-box">
        <div class="stat-number"><?= file_exists(LOG_FILE) ? round(filesize(LOG_FILE) / 1024, 1) : 0 ?> KB</div>
        <div class="stat-label">Log Size</div>
    </div>
    <div class="stat-box">
        <div class="stat-number"><?= date('Y-m-d H:i:s') ?></div>
        <div class="stat-label">Current Time</div>
    </div>
</div>

<div class="upload-panel">
    <h3>Manual Upload</h3>
    <form method="POST">
        <input type="hidden" name="action" value="upload_now">
        <div class="form-grid" style="margin-bottom: 20px;">
            <div class="field">
                <label>From Date (optional)</label>
                <input type="date" name="date_from">
            </div>
            <div class="field">
                <label>To Date (optional)</label>
                <input type="date" name="date_to">
            </div>
        </div>
        <div class="button-group">
            <button type="submit" class="btn btn-primary">Generate and Upload Now</button>
            <button type="button" class="btn btn-outline" onclick="window.location.href='?action=upload_24h'">Upload Last 24 Hours</button>
        </div>
    </form>
    
    <?php if (isset($_GET['action']) && $_GET['action'] === 'upload_24h'): ?>
    <?php
        require_once dirname(__FILE__) . '/upload_config.php';
        $result = uploadLatestData(date('Y-m-d H:i:s', strtotime('-24 hours')), date('Y-m-d H:i:s'));
    ?>
    <div class="result-message <?= $result['success'] ? 'result-success' : 'result-error' ?>" style="margin-top: 16px;">
        <strong><?= $result['success'] ? 'Upload Successful' : 'Upload Failed' ?></strong><br>
        <?= htmlspecialchars($result['message']) ?>
    </div>
    <?php endif; ?>
    
    <div class="info-box">
        <strong>Auto-Removal Active</strong><br>
        When a new file is uploaded, all existing CSV files on the remote server are automatically deleted. Only the latest file is kept.
    </div>
</div>

<div class="upload-panel">
    <h3>Windows Task Scheduler Setup (Every 60 Minutes)</h3>
    <p>To run this script every 60 minutes automatically, follow these steps:</p>
    
    <div class="task-scheduler">
        <strong>Step 1: Find your PHP path</strong><br>
        Open Command Prompt and run:<br>
        <code>dir C:\wamp64\bin\php\</code><br>
        Note the folder name (e.g., php8.2.0, php7.4.33)<br><br>
        
        <strong>Step 2: Create a batch file</strong><br>
        Create a file named <code>run_upload.bat</code> in:<br>
        <code>C:\wamp64\www\greenhouse\csv_upload\run_upload.bat</code><br><br>
        
        <strong>Content of run_upload.bat:</strong><br>
        <code style="display:block; background:#1a1a2e; padding:10px;">
@echo off<br>
cd /d C:\wamp64\www\greenhouse\csv_upload<br>
C:\wamp64\bin\php\php8.2.0\php.exe csv_uploader.php --last-24h >> upload_cron.log 2>&1
        </code><br>
        
        <strong>Step 3: Open Task Scheduler</strong><br>
        Press Windows + R, type <code>taskschd.msc</code>, press Enter<br><br>
        
        <strong>Step 4: Create Basic Task</strong><br>
        - Click "Create Basic Task" on the right<br>
        - Name: "Greenhouse CSV Upload"<br>
        - Trigger: "Daily" → "Repeat every 1 hour" → "Indefinitely"<br>
        - Action: "Start a program" → Browse to run_upload.bat<br>
        - Click Finish<br><br>
        
        <strong>Step 5: Test manually</strong><br>
        <code>C:\wamp64\bin\php\php8.2.0\php.exe csv_uploader.php --last-24h</code>
    </div>
    
    <div class="info-box" style="margin-top: 16px;">
        <strong>Your Paths:</strong><br>
        Project Root: <code>C:\wamp64\www\greenhouse\</code><br>
        CSV Upload Folder: <code>C:\wamp64\www\greenhouse\csv_upload\</code><br>
        db.php Location: <code>C:\wamp64\www\greenhouse\db.php</code>
    </div>
</div>

<div class="log-panel">
    <div class="log-header">
        <span>Upload Log (Last 100 lines)</span>
        <button class="btn btn-outline btn-sm" onclick="if(confirm('Clear log?')) window.location.href='?action=clear_log'">Clear Log</button>
    </div>
    <div class="log-content">
        <?= htmlspecialchars($logContent ?: 'No log entries yet. Click "Upload Last 24 Hours" to test.') ?>
    </div>
</div>

<?php if (isset($_GET['action']) && $_GET['action'] === 'clear_log'): ?>
<?php
    if (file_exists(LOG_FILE)) {
        file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " Log cleared by user\n");
        echo '<script>window.location.href="schedule_upload.php";</script>';
    }
?>
<?php endif; ?>

</div>
</body></html>