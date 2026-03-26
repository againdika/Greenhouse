<?php
// ══════════════════════════════════════════════════════════════════════
//  api.php
//  Accepts CH1 (humidity/sprinkler) + CH2 (temperature/fan) relay state
// ══════════════════════════════════════════════════════════════════════
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/includes.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// ── Authenticate ──────────────────────────────────────────────────────
$key      = trim($_POST['key']       ?? '');
$deviceId = trim($_POST['device_id'] ?? 'DEFAULT');

$device = null;
try {
    $stmt = getDB()->prepare('SELECT * FROM edge_devices WHERE device_id=:d AND is_active=1 LIMIT 1');
    $stmt->execute([':d' => $deviceId]);
    $device = $stmt->fetch();
} catch (Exception $e) {}

if (!($device && $key === $device['api_key']) && $key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Validate sensor values ────────────────────────────────────────────
$temp = isset($_POST['temp'])     ? (float)$_POST['temp']     : null;
$hum  = isset($_POST['humidity']) ? (float)$_POST['humidity'] : null;
$gas  = isset($_POST['gas'])      ? (int)  $_POST['gas']      : null;

if ($temp === null || $hum === null || $gas === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing: temp, humidity, gas']);
    exit;
}
if ($temp < -40 || $temp > 80 || $hum < 0 || $hum > 100 || $gas < 0 || $gas > 4095) {
    http_response_code(400);
    echo json_encode(['error' => 'Values out of sensor range']);
    exit;
}

$ts = tempStatus($temp);
$hs = humStatus($hum);
$gs = gasStatus($gas);

// ── CH1: Sprinkler / humidity relay ──────────────────────────────────
$ch1On      = isset($_POST['motor_on'])      ? (int)(bool)$_POST['motor_on']  : 0;
$ch1RunSec  = isset($_POST['motor_run_sec']) ? (int)$_POST['motor_run_sec']   : 0;
$ch1PendSec = isset($_POST['motor_pending']) ? (int)$_POST['motor_pending']   : 0;

// ── CH2: Cooling fan / temperature relay ──────────────────────────────
$ch2On      = isset($_POST['ch2_on'])        ? (int)(bool)$_POST['ch2_on']    : 0;
$ch2RunSec  = isset($_POST['ch2_run_sec'])   ? (int)$_POST['ch2_run_sec']     : 0;
$ch2PendSec = isset($_POST['ch2_pending'])   ? (int)$_POST['ch2_pending']     : 0;

// ── Trigger reason ────────────────────────────────────────────────────
$allowed = ['none', 'humidity', 'temperature', 'both'];
$trigger = trim($_POST['trigger'] ?? 'none');
if (!in_array($trigger, $allowed)) $trigger = 'none';

// ── Save to database ──────────────────────────────────────────────────
try {
    $db = getDB();
    $db->prepare(
        'INSERT INTO sensor_log
         (device_id, temperature, humidity, gas,
          temp_status, hum_status, gas_status,
          motor_on, motor_run_sec, motor_pending_sec,
          ch2_on, ch2_run_sec, ch2_pending_sec,
          trigger_reason, source)
         VALUES
         (:did, :temp, :hum, :gas,
          :ts, :hs, :gs,
          :ch1, :ch1r, :ch1p,
          :ch2, :ch2r, :ch2p,
          :tr, "esp32")'
    )->execute([
        ':did'  => $deviceId,
        ':temp' => $temp,  ':hum'  => $hum,  ':gas'  => $gas,
        ':ts'   => $ts,    ':hs'   => $hs,   ':gs'   => $gs,
        ':ch1'  => $ch1On, ':ch1r' => $ch1RunSec, ':ch1p' => $ch1PendSec,
        ':ch2'  => $ch2On, ':ch2r' => $ch2RunSec, ':ch2p' => $ch2PendSec,
        ':tr'   => $trigger,
    ]);
    $newId    = (int)$db->lastInsertId();
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;

    if ($device) {
        $db->prepare('UPDATE edge_devices SET last_seen=NOW(),last_ip=:ip WHERE device_id=:d')
           ->execute([':ip' => $clientIp, ':d' => $deviceId]);
    } else {
        try {
            $db->prepare('INSERT IGNORE INTO edge_devices
                (device_id,display_name,location_desc,api_key,last_seen,last_ip)
                VALUES(:d,:n,"Auto-registered",:k,NOW(),:ip)')
               ->execute([':d'=>$deviceId,':n'=>'Device '.$deviceId,':k'=>$key,':ip'=>$clientIp]);
        } catch (Exception $e) {}
    }

    echo json_encode([
        'ok'             => true,
        'id'             => $newId,
        'device_id'      => $deviceId,
        'temp_status'    => $ts,
        'hum_status'     => $hs,
        'gas_status'     => $gs,
        'ch1_on'         => (bool)$ch1On,
        'ch2_on'         => (bool)$ch2On,
        'trigger_reason' => $trigger,
        'recorded_at'    => date('Y-m-d H:i:s'),
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
