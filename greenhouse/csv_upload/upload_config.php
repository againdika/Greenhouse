<?php
// ══════════════════════════════════════════════════════════════════════
//  upload_config.php  —  Remote Upload Configuration (CLEAN VERSION)
// ══════════════════════════════════════════════════════════════════════

// Remote server details - UPDATE THESE
define('REMOTE_HOST', ''); //<-- cloud server IP or hostname
define('REMOTE_PORT', ''); // For SCP: typically 22, but may be different if your server uses a custom SSH port
define('REMOTE_USER', ''); // SSH username for SCP upload
define('REMOTE_PASS', ''); // SSH password for SCP upload (if using password authentication)

// Remote paths for Ubuntu + XAMPP
define('REMOTE_API_URL', '');   // Example: 'http://yourserver.com/upload_csv.php' - This should point to the API endpoint on your server that handles the CSV upload
define('REMOTE_PATH', ''); // Example: '/var/www/html/uploads/' - This is the directory on the remote server where you want to store the uploaded CSV files (used for SCP upload)

// Local settings for Windows WAMP
define('CSV_EXPORT_DIR', __DIR__ . '\\csv_exports\\');
define('LOG_FILE', __DIR__ . '\\upload_log.txt');
define('UPLOAD_INTERVAL_MINUTES', 60);
define('KEEP_ONLY_LATEST', true);

// Include your existing database connection
require_once dirname(__DIR__) . '/db.php';

function generateSensorCSV($dateFrom = null, $dateTo = null) {
    try {
        $db = getDB();
        
        if (!$dateFrom && !$dateTo) {
            $dateFrom = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $dateTo = date('Y-m-d H:i:s');
            $where = 'WHERE recorded_at BETWEEN :from AND :to';
            $params = [':from' => $dateFrom, ':to' => $dateTo];
        } elseif ($dateFrom && $dateTo) {
            $where = 'WHERE DATE(recorded_at) BETWEEN :from AND :to';
            $params = [':from' => $dateFrom, ':to' => $dateTo];
        } else {
            $where = '';
            $params = [];
        }
        
        $stmt = $db->prepare("
            SELECT 
                id, recorded_at, temperature, humidity, gas,
                temp_status, hum_status, gas_status, source,
                motor_on, motor_run_sec, ch2_on, ch2_run_sec,
                trigger_reason, device_id, notes
            FROM sensor_log 
            {$where}
            ORDER BY recorded_at ASC
        ");
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($data)) {
            writeLog("No data found for export");
            return false;
        }
        
        if (!is_dir(CSV_EXPORT_DIR)) {
            mkdir(CSV_EXPORT_DIR, 0755, true);
        }
        
        if (KEEP_ONLY_LATEST) {
            $existingFiles = glob(CSV_EXPORT_DIR . '*.csv');
            foreach ($existingFiles as $oldFile) {
                if (is_file($oldFile)) {
                    unlink($oldFile);
                    writeLog("Removed old local file: " . basename($oldFile));
                }
            }
        }
        
        $filename = 'greenhouse_log_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = CSV_EXPORT_DIR . $filename;
        
        $fp = fopen($filepath, 'w');
        
        fputcsv($fp, [
            'Record ID', 'Date and Time (SLT)', 'Temperature (C)', 'Humidity (%)',
            'Air Quality (Gas)', 'Temperature Status', 'Humidity Status',
            'Air Quality Status', 'Source', 'Motor ON (Sprinkler)',
            'Motor Run Time (sec)', 'Fan ON (Cooling)', 'Fan Run Time (sec)',
            'Trigger Reason', 'Device ID', 'Notes'
        ]);
        
        foreach ($data as $row) {
            fputcsv($fp, [
                $row['id'], $row['recorded_at'], $row['temperature'], $row['humidity'],
                $row['gas'], $row['temp_status'], $row['hum_status'], $row['gas_status'],
                $row['source'] == 'esp32' ? 'Automatic' : 'Manual',
                $row['motor_on'] ? 'ON' : 'OFF', $row['motor_run_sec'] ?? 0,
                $row['ch2_on'] ? 'ON' : 'OFF', $row['ch2_run_sec'] ?? 0,
                $row['trigger_reason'] ?? 'none', $row['device_id'] ?? 'DEFAULT',
                $row['notes'] ?? ''
            ]);
        }
        
        fclose($fp);
        
        $fp = fopen($filepath, 'a');
        fputcsv($fp, []);
        fputcsv($fp, ['# SUMMARY', 'Total Records', count($data)]);
        fputcsv($fp, ['# Generated', date('Y-m-d H:i:s') . ' SLT']);
        fclose($fp);
        
        writeLog("CSV generated: {$filename} with " . count($data) . " records");
        
        return $filepath;
        
    } catch (Exception $e) {
        writeLog("ERROR generating CSV: " . $e->getMessage());
        return false;
    }
}

function uploadViaSCP($localFile) {
    $result = ['success' => false, 'message' => ''];
    
    if (!file_exists($localFile)) {
        $result['message'] = "Local file not found: {$localFile}";
        writeLog($result['message']);
        return $result;
    }
    
    writeLog("Using HTTP upload for Windows environment");
    $httpResult = uploadViaHTTP($localFile);
    
    if ($httpResult['success']) {
        $result['success'] = true;
        $result['message'] = "HTTP upload successful: " . $httpResult['message'];
    } else {
        $result['message'] = "HTTP upload failed: " . $httpResult['message'];
    }
    
    return $result;
}

function uploadViaHTTP($localFile) {
    $result = ['success' => false, 'message' => ''];
    
    if (!file_exists($localFile)) {
        $result['message'] = "Local file not found for HTTP upload";
        return $result;
    }
    
    $csvContent = file_get_contents($localFile);
    $filename = basename($localFile);
    
    $postData = [
        'csv_data' => base64_encode($csvContent),
        'filename' => $filename,
        'timestamp' => date('Y-m-d H:i:s'),
        'server_name' => gethostname(),
        'api_key' => defined('API_KEY') ? API_KEY : 'greenhouse_upload',
        'replace_existing' => 'true'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, REMOTE_API_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        $result['message'] = "HTTP upload curl error: " . $curlError;
        writeLog($result['message']);
    } elseif ($httpCode == 200) {
        $result['success'] = true;
        $result['message'] = "HTTP upload successful";
        writeLog($result['message'] . " - Response: " . $response);
    } else {
        $result['message'] = "HTTP upload failed (HTTP {$httpCode}): " . $response;
        writeLog($result['message']);
    }
    
    return $result;
}

function writeLog($message) {
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

function uploadLatestData($dateFrom = null, $dateTo = null) {
    writeLog("===== Starting upload process =====");
    
    $csvFile = generateSensorCSV($dateFrom, $dateTo);
    
    if (!$csvFile) {
        writeLog("No CSV generated - no data to upload");
        return ['success' => false, 'message' => 'No data to upload'];
    }
    
    $result = uploadViaSCP($csvFile);
    
    writeLog("===== Upload process completed: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . " =====");
    
    return $result;
}
?>