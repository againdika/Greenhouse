<?php
// csv_uploader.php - Windows compatible
require_once dirname(__FILE__) . '/upload_config.php';

$dateFrom = null;
$dateTo = null;

// Parse command line arguments
if (isset($argv)) {
    for ($i = 1; $i < count($argv); $i++) {
        switch ($argv[$i]) {
            case '--last-24h':
                $dateFrom = date('Y-m-d H:i:s', strtotime('-24 hours'));
                $dateTo = date('Y-m-d H:i:s');
                break;
            case '--help':
                echo "Greenhouse CSV Uploader\n";
                echo "Usage: php csv_uploader.php --last-24h\n";
                exit(0);
        }
    }
}

echo "Greenhouse CSV Uploader\n";
echo "======================\n\n";
echo "Server: " . REMOTE_HOST . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$result = uploadLatestData($dateFrom, $dateTo);

if ($result['success']) {
    echo "SUCCESS: " . $result['message'] . "\n";
} else {
    echo "ERROR: " . $result['message'] . "\n";
}

echo "\nLog file: " . LOG_FILE . "\n";
?>