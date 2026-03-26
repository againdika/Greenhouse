<?php
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/includes.php';
require_once dirname(__FILE__) . '/auth.php';
requireLogin();

// ── Sensor + relay data ───────────────────────────────────────────────
$dbOK = false; $latest = null; $stats = null; $chart = [];
try {
    $db     = getDB();
    $latest = $db->query('SELECT * FROM sensor_log ORDER BY id DESC LIMIT 1')->fetch();
    $stats  = $db->query('
        SELECT COUNT(*) AS total,
            ROUND(AVG(temperature),1) AS avg_temp, ROUND(MIN(temperature),1) AS min_temp, ROUND(MAX(temperature),1) AS max_temp,
            ROUND(AVG(humidity),1)    AS avg_hum,  ROUND(MIN(humidity),1)    AS min_hum,  ROUND(MAX(humidity),1)    AS max_hum,
            ROUND(AVG(gas))           AS avg_gas,  MIN(gas) AS min_gas, MAX(gas) AS max_gas,
            SUM(temp_status="DANGER") AS temp_danger, SUM(temp_status="WARNING") AS temp_warn,
            SUM(hum_status="DANGER")  AS hum_danger,  SUM(hum_status="WARNING")  AS hum_warn,
            SUM(gas_status="DANGER")  AS gas_danger,  SUM(gas_status="WARNING")  AS gas_warn,
            SUM(source="manual")      AS manual_count, SUM(source="esp32") AS esp32_count,
            SUM(motor_on=1)           AS ch1_on_count,
            SUM(ch2_on=1)             AS ch2_on_count
        FROM sensor_log WHERE recorded_at >= NOW() - INTERVAL 24 HOUR
    ')->fetch();
    $chart = array_reverse($db->query(
        'SELECT recorded_at, temperature, humidity, gas FROM sensor_log ORDER BY id DESC LIMIT 60'
    )->fetchAll());
    $dbOK = true;
} catch (Exception $e) { $dbErr = $e->getMessage(); }

$chartLabels = array_map(fn($r) => date('H:i', strtotime($r['recorded_at'])), $chart);
$chartTemp   = array_map(fn($r) => (float)$r['temperature'], $chart);
$chartHum    = array_map(fn($r) => (float)$r['humidity'],    $chart);
$chartGas    = array_map(fn($r) => (int)  $r['gas'],         $chart);

// ── Relay state from latest reading ──────────────────────────────────
// CH1 — sprinkler pump (humidity)
$ch1On         = $latest ? (bool)($latest['motor_on']          ?? 0) : false;
$ch1RunSec     = $latest ? (int) ($latest['motor_run_sec']     ?? 0) : 0;
$ch1PendingSec = $latest ? (int) ($latest['motor_pending_sec'] ?? 0) : 0;
// CH2 — cooling fan (temperature)
$ch2On         = $latest ? (bool)($latest['ch2_on']            ?? 0) : false;
$ch2RunSec     = $latest ? (int) ($latest['ch2_run_sec']       ?? 0) : 0;
$ch2PendingSec = $latest ? (int) ($latest['ch2_pending_sec']   ?? 0) : 0;
// Trigger reason
$triggerReason = $latest ? ($latest['trigger_reason'] ?? 'none') : 'none';
// Live readings
$humNow        = $latest ? (float)$latest['humidity']    : 0;
$tempNow       = $latest ? (float)$latest['temperature'] : 0;

function fmtSec(int $s): string {
    if ($s <= 0) return '0s';
    $m = intdiv($s, 60); $r = $s % 60;
    return $m > 0 ? "{$m}m {$r}s" : "{$r}s";
}

// ── Edge devices ──────────────────────────────────────────────────────
$allDevices = $offlineDevices = [];
try {
    $allDevices = getDB()->query('
        SELECT device_id, display_name, is_active, last_seen,
               TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_ago
        FROM edge_devices WHERE is_active=1 ORDER BY display_name ASC
    ')->fetchAll();
    foreach ($allDevices as $dev) {
        if (($dev['last_seen'] ? (int)$dev['seconds_ago'] : PHP_INT_MAX) >= SENSOR_TIMEOUT_SEC)
            $offlineDevices[] = $dev;
    }
} catch (Exception $e) {}

// ── Weather ───────────────────────────────────────────────────────────
$weather   = getWeather();
$wxCurrent = $weather['current'] ?? null;
$wxNext6   = $weather['next6']   ?? [];
$wxDaily   = $weather['daily']   ?? [];
$wxSummary = $weather['summary'] ?? [];
$wxFailed  = ($weather === null);

// ── Build all alerts ──────────────────────────────────────────────────
$allAlerts = [];

// Device offline
foreach ($offlineDevices as $dev) {
    $agoMin = $dev['last_seen'] ? round((int)$dev['seconds_ago']/60) : null;
    $allAlerts[] = ['danger', 'SENSOR OFFLINE — ' . strtoupper($dev['display_name']),
        'No data from "' . $dev['display_name'] . '" for ' . ($agoMin !== null ? $agoMin . ' minutes' : 'an unknown time') . '. Check power and WiFi.'];
}

// CH1 sprinkler alert
if ($ch1On) {
    $allAlerts[] = ['info', 'CH1 — SPRINKLER RUNNING',
        'Indoor humidity (' . $humNow . '%) has been above ' . MOTOR_HUM_THRESHOLD . '% for over 5 minutes. Sprinkler pump is active (running ' . fmtSec($ch1RunSec) . '). Will stop automatically when humidity normalises.'];
} elseif ($ch1PendingSec > 0) {
    $allAlerts[] = ['warn', 'CH1 — SPRINKLER PENDING',
        'Humidity (' . $humNow . '%) above ' . MOTOR_HUM_THRESHOLD . '%. Sprinkler activates in approximately ' . fmtSec($ch1PendingSec) . ' unless humidity drops first.'];
}

// CH2 cooling fan alert
if ($ch2On) {
    $allAlerts[] = ['warn', 'CH2 — COOLING FAN RUNNING',
        'Inside temperature (' . $tempNow . ' C) has been above ' . MOTOR_TEMP_THRESHOLD . ' C for over 5 minutes. Cooling fan is active (running ' . fmtSec($ch2RunSec) . '). Will stop automatically when temperature normalises.'];
} elseif ($ch2PendingSec > 0) {
    $allAlerts[] = ['warn', 'CH2 — COOLING FAN PENDING',
        'Temperature (' . $tempNow . ' C) above ' . MOTOR_TEMP_THRESHOLD . ' C. Cooling fan activates in approximately ' . fmtSec($ch2PendingSec) . ' unless temperature drops first.'];
}

// Greenhouse sensor alerts
if ($latest) {
    $ts = $latest['temp_status']; $hs = $latest['hum_status']; $gs = $latest['gas_status'];
    if ($ts==='DANGER')      $allAlerts[]=['danger','GREENHOUSE — TEMPERATURE DANGER',
        'Temperature critically '.($tempNow<15?'low':'high').' at '.$tempNow.' C. Immediate action required.'];
    elseif ($ts==='WARNING') $allAlerts[]=['warn',  'GREENHOUSE — TEMPERATURE WARNING',
        'Inside temperature is '.$tempNow.' C. Safe range is 20 to 30 C.'];
    if ($hs==='DANGER')      $allAlerts[]=['danger','GREENHOUSE — HUMIDITY DANGER',
        'Humidity '.($humNow<40?'critically low':'critically high').' at '.$humNow.'%. Risk of crop damage.'];
    elseif ($hs==='WARNING') $allAlerts[]=['warn',  'GREENHOUSE — HUMIDITY WARNING',
        'Inside humidity is '.$humNow.'%. Safe range is 60 to 80%.'];
    if ($gs==='DANGER')      $allAlerts[]=['danger','GREENHOUSE — AIR QUALITY DANGER',
        'Air quality very poor. Ventilate immediately.'];
    elseif ($gs==='WARNING') $allAlerts[]=['warn',  'GREENHOUSE — AIR QUALITY WARNING',
        'Air quality becoming elevated. Check ventilation.'];
}

// Weather structural alerts
if ($weather && $wxCurrent) {
    $wc=$wxCurrent['weather_code']??0; $wind=$wxCurrent['wind']??0;
    $gust=$wxCurrent['wind_gust']??0; $hum=$wxCurrent['humidity']??0;
    $maxGust6h=$wxSummary['max_gust_6h']??0;
    if (isStormCode($wc))
        $allAlerts[]=['danger','WEATHER — STORM WARNING',
            'Thunderstorm now. Close all vents and doors. Secure all panels and frames.'];
    elseif (array_filter($wxNext6, fn($h)=>isStormCode($h['weather_code'])))
        $allAlerts[]=['danger','WEATHER — STORM APPROACHING',
            'Thunderstorm forecast in 6 hours. Close vents and secure covers now.'];
    if ($wind>=WEATHER_WIND_DANGER_KMH||$gust>=WEATHER_WIND_DANGER_KMH)
        $allAlerts[]=['danger','WEATHER — HIGH WIND DANGER',
            'Wind '.$wind.' km/h, gusts '.$gust.' km/h. Secure all panels and doors immediately.'];
    elseif ($wind>=WEATHER_WIND_WARNING_KMH||$gust>=WEATHER_WIND_WARNING_KMH)
        $allAlerts[]=['warn','WEATHER — HIGH WIND WARNING',
            'Wind '.$wind.' km/h (gusts '.$gust.' km/h). Check covers and fastenings.'];
    elseif ($maxGust6h>=WEATHER_WIND_DANGER_KMH)
        $allAlerts[]=['warn','WEATHER — STRONG WIND EXPECTED',
            'Gusts up to '.$maxGust6h.' km/h expected in 6 hours. Secure covers now.'];
    if (isRainyCode($wc))
        $allAlerts[]=['info','WEATHER — RAIN OUTSIDE',
            'Rain in progress. Outdoor temperature will drop and humidity will rise. Close vents if open. Irrigation continues as normal.'];
    if ($hum>=WEATHER_HUMIDITY_HIGH_PCT)
        $allAlerts[]=['warn','WEATHER — HIGH OUTDOOR HUMIDITY',
            'Outdoor humidity '.$hum.'%. Close vents to prevent raising indoor humidity and risking fungal disease.'];
    if (empty($allAlerts))
        $allAlerts[]=['ok','ALL CONDITIONS GOOD',
            'All sensors online, readings within range, relays off, weather calm in '.WEATHER_LOCATION.'. Normal operations can continue.'];
} elseif (empty($allAlerts) && $latest) {
    $allAlerts[]=['ok','GREENHOUSE SENSORS NORMAL',
        'All readings within safe range. Weather data unavailable — check outside conditions manually.'];
}

echo sharedHead('Live Status', true);
echo sharedCSS();
?>
<style>
/* ── Offline popup ──────────────────────────────────────────────────── */
.offline-popup { position:fixed; top:84px; right:24px; z-index:500; background:var(--red-bg); border:2px solid var(--red); border-radius:var(--radius-lg); padding:18px 22px; box-shadow:0 8px 32px rgba(127,29,29,.25); max-width:380px; animation:slideIn .3s ease; }
@keyframes slideIn { from{transform:translateX(120%);opacity:0} to{transform:translateX(0);opacity:1} }
.popup-title { font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.09em; color:var(--red-txt); margin-bottom:8px; }
.popup-msg   { font-size:.9rem; color:var(--red-txt); line-height:1.55; margin-bottom:12px; }
.popup-close { font-size:.82rem; font-weight:600; color:var(--red); background:transparent; border:1px solid var(--red-bdr); border-radius:var(--radius); padding:5px 14px; cursor:pointer; }
.popup-close:hover { background:var(--red); color:#fff; }

/* ── Unified alerts ─────────────────────────────────────────────────── */
.all-alerts { display:flex; flex-direction:column; gap:10px; margin-bottom:28px; }
.a-alert { padding:16px 22px; border-radius:var(--radius); font-size:1rem; border:1px solid; border-left:5px solid; display:flex; align-items:flex-start; gap:16px; line-height:1.65; }
.a-alert.danger { background:var(--red-bg);   border-color:var(--red-bdr);   color:var(--red-txt);   border-left-color:var(--red); }
.a-alert.warn   { background:var(--amber-bg); border-color:var(--amber-bdr); color:var(--amber-txt); border-left-color:#d97706; }
.a-alert.ok     { background:var(--green-bg); border-color:var(--green-bdr); color:var(--green-txt); border-left-color:var(--green); }
.a-alert.info   { background:var(--blue-bg);  border-color:var(--blue-bdr);  color:#1e3a8a;          border-left-color:var(--blue); }
.a-tag { font-size:.74rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; white-space:nowrap; padding-top:3px; min-width:210px; flex-shrink:0; opacity:.9; }

/* ── Device chips ───────────────────────────────────────────────────── */
.device-status-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:22px; }
.dev-chip { display:flex; align-items:center; gap:8px; background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:6px 14px; font-size:.88rem; box-shadow:var(--shadow); }
.dev-chip.online  { border-color:var(--green-bdr); }
.dev-chip.offline { border-color:var(--red-bdr); background:var(--red-bg); }
.dev-chip-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.dev-chip-dot.online  { background:var(--green); }
.dev-chip-dot.offline { background:var(--red); }
.dev-chip-name { font-weight:600; color:var(--ink-2); }
.dev-chip-time { color:var(--ink-4); font-size:.8rem; }
.dev-chip-time.offline-time { color:var(--red-txt); font-weight:600; }

/* ── Sensor tiles ───────────────────────────────────────────────────── */
.sensor-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-bottom:20px; }
.sensor-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:28px 30px; box-shadow:var(--shadow); border-top:5px solid var(--border); }
.sensor-card.s-ok     { border-top-color:var(--green); }
.sensor-card.s-warn   { border-top-color:#d97706; }
.sensor-card.s-danger { border-top-color:var(--red); }
.sc-label  { font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--ink-3); margin-bottom:14px; }
.sc-value  { font-size:3.8rem; font-weight:700; line-height:1; color:var(--ink); margin-bottom:6px; }
.sc-unit   { font-size:.9rem; color:var(--ink-4); margin-bottom:8px; }
.sc-device { font-size:.76rem; color:var(--ink-4); margin-bottom:14px; }
.status-pill { display:inline-block; font-size:.82rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; padding:5px 16px; border-radius:6px; border:1px solid; }
.pill-ok     { color:var(--green-txt); border-color:var(--green-bdr); background:var(--green-bg); }
.pill-warn   { color:var(--amber-txt); border-color:var(--amber-bdr); background:var(--amber-bg); }
.pill-danger { color:var(--red-txt);   border-color:var(--red-bdr);   background:var(--red-bg); }

/* ── Dual relay tile ────────────────────────────────────────────────── */
.relay-tile-grid {
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:16px;
  margin-bottom:28px;
}
.relay-tile {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius-lg); box-shadow:var(--shadow);
  overflow:hidden; border-top:5px solid var(--border-dk);
  display:flex; flex-direction:column;
}
.relay-tile.ch1-running { border-top-color:#1a56db; }
.relay-tile.ch1-pending { border-top-color:#d97706; }
.relay-tile.ch1-off     { border-top-color:var(--border-dk); }
.relay-tile.ch2-running { border-top-color:#dc2626; }
.relay-tile.ch2-pending { border-top-color:#d97706; }
.relay-tile.ch2-off     { border-top-color:var(--border-dk); }

/* Tile header */
.rt-header {
  display:flex; align-items:center; gap:14px;
  padding:16px 20px; border-bottom:1px solid var(--border);
}
.rt-header.running-hum  { background:#dbeafe; }
.rt-header.running-temp { background:#fee2e2; }
.rt-header.pending      { background:#fef3c7; }
.rt-header.off          { background:#f3f4f6; }
.rt-icon {
  width:48px; height:48px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  font-size:20px; flex-shrink:0;
}
.rt-icon.running-hum  { background:#1a56db; animation:pulse-blue 1.4s ease-in-out infinite; }
.rt-icon.running-temp { background:#dc2626; animation:pulse-red  1.4s ease-in-out infinite; }
.rt-icon.pending      { background:#d97706; }
.rt-icon.off          { background:#9ca3af; }
@keyframes pulse-blue { 0%,100%{box-shadow:0 0 0 0 rgba(26,86,219,.5)} 50%{box-shadow:0 0 0 10px rgba(26,86,219,0)} }
@keyframes pulse-red  { 0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,.5)} 50%{box-shadow:0 0 0 10px rgba(220,38,38,0)} }
.rt-state-label { font-size:1.1rem; font-weight:700; }
.rt-state-label.running-hum  { color:#1e3a8a; }
.rt-state-label.running-temp { color:#7f1d1d; }
.rt-state-label.pending      { color:#78350f; }
.rt-state-label.off          { color:#6b7280; }
.rt-state-sub { font-size:.82rem; font-weight:500; margin-top:3px; }
.rt-state-sub.running-hum  { color:#1e40af; }
.rt-state-sub.running-temp { color:#991b1b; }
.rt-state-sub.pending      { color:#92400e; }
.rt-state-sub.off          { color:#9ca3af; }
.rt-channel-badge {
  margin-left:auto; font-size:.72rem; font-weight:700; letter-spacing:.06em;
  text-transform:uppercase; padding:4px 10px; border-radius:5px; border:1px solid;
}
.badge-ch1 { color:#1e3a8a; border-color:var(--blue-bdr); background:var(--blue-bg); }
.badge-ch2 { color:#7f1d1d; border-color:var(--red-bdr);  background:var(--red-bg); }

/* Tile body */
.rt-body { padding:18px 20px; flex:1; display:flex; flex-direction:column; gap:14px; }

/* Progress bar */
.rt-bar-row { display:flex; flex-direction:column; gap:5px; }
.rt-bar-label-row { display:flex; justify-content:space-between; font-size:.8rem; }
.rt-bar-label-row .key { color:var(--ink-3); font-weight:500; }
.rt-bar-label-row .val { font-weight:700; }
.rt-bar-label-row .val.above-hum  { color:#1a56db; }
.rt-bar-label-row .val.above-temp { color:#dc2626; }
.rt-bar-label-row .val.below      { color:var(--green-txt); }
.rt-bar-track { height:9px; background:#e5e7eb; border-radius:5px; overflow:hidden; position:relative; }
.rt-bar-fill  { height:100%; border-radius:5px; transition:width .4s; }
.rt-bar-fill.fill-hum-above  { background:#1a56db; }
.rt-bar-fill.fill-hum-below  { background:#6ee7b7; }
.rt-bar-fill.fill-temp-above { background:#dc2626; }
.rt-bar-fill.fill-temp-below { background:#6ee7b7; }
/* Threshold marker line */
.rt-bar-track .threshold-line {
  position:absolute; top:0; bottom:0; width:2px; background:#374151; opacity:.5;
}

/* Stats row at bottom */
.rt-stats { display:grid; grid-template-columns:1fr 1fr; gap:10px; border-top:1px solid var(--border); padding-top:14px; }
.rt-stat-item { display:flex; flex-direction:column; gap:2px; }
.rt-stat-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--ink-4); }
.rt-stat-value { font-size:1.1rem; font-weight:700; color:var(--ink-2); }
.rt-stat-sub   { font-size:.76rem; color:var(--ink-3); }

/* ── Weather tiles ──────────────────────────────────────────────────── */
.wx-tile-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:14px; margin-bottom:28px; }
.wx-tile { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:18px 16px; box-shadow:var(--shadow); border-top:4px solid var(--border-dk); display:flex; flex-direction:column; }
.wx-tile.t-ok      { border-top-color:var(--green); }
.wx-tile.t-warn    { border-top-color:#d97706; }
.wx-tile.t-danger  { border-top-color:var(--red); }
.wx-tile.t-info    { border-top-color:var(--blue); }
.wx-tile.t-neutral { border-top-color:var(--border-dk); }
.wt-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--ink-4); margin-bottom:10px; }
.wt-value { font-size:1.9rem; font-weight:700; line-height:1; color:var(--ink); margin-bottom:3px; }
.wt-value.v-ok     { color:var(--green-txt); }
.wt-value.v-warn   { color:#b45309; }
.wt-value.v-danger { color:var(--red-txt); }
.wt-unit  { font-size:.82rem; color:var(--ink-4); margin-bottom:10px; }
.wt-sub   { font-size:.8rem; color:var(--ink-3); line-height:1.5; margin-top:auto; }
.wt-pill  { display:inline-block; font-size:.68rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; padding:3px 9px; border-radius:4px; border:1px solid; margin-top:8px; align-self:flex-start; }
.wp-ok     { color:var(--green-txt); border-color:var(--green-bdr); background:var(--green-bg); }
.wp-warn   { color:var(--amber-txt); border-color:var(--amber-bdr); background:var(--amber-bg); }
.wp-danger { color:var(--red-txt);   border-color:var(--red-bdr);   background:var(--red-bg); }
.wp-info   { color:#1e3a8a;          border-color:var(--blue-bdr);  background:var(--blue-bg); }
.wp-neutral{ color:var(--ink-3);     border-color:var(--border-dk); background:var(--bg); }

/* ── Hourly tiles ───────────────────────────────────────────────────── */
.hourly-tile-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin-bottom:28px; }
.hourly-tile { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:14px 12px; box-shadow:var(--shadow); text-align:center; border-top:3px solid var(--border); }
.hourly-tile.ht-danger { border-top-color:var(--red); }
.hourly-tile.ht-warn   { border-top-color:#d97706; }
.hourly-tile.ht-info   { border-top-color:var(--blue); }
.hourly-tile.ht-ok     { border-top-color:var(--green); }
.ht-time { font-size:.76rem; font-weight:700; color:var(--ink-3); margin-bottom:7px; text-transform:uppercase; letter-spacing:.05em; }
.ht-temp { font-size:1.3rem; font-weight:700; color:var(--ink); margin-bottom:4px; }
.ht-cond { font-size:.72rem; color:var(--ink-3); margin-bottom:8px; line-height:1.4; }
.ht-rainbar { height:5px; background:var(--bg); border-radius:3px; margin:0 4px 5px; overflow:hidden; }
.ht-rainbar-fill { height:100%; border-radius:3px; }
.rfill-danger { background:var(--red); }
.rfill-warn   { background:#d97706; }
.rfill-info   { background:var(--blue); }
.ht-stat { font-size:.72rem; color:var(--ink-3); line-height:1.6; }
.ht-stat.s-warn   { color:#b45309; font-weight:600; }
.ht-stat.s-danger { color:var(--red-txt); font-weight:700; }
.ht-advice { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin-top:5px; }
.ht-advice.adv-danger { color:var(--red-txt); }
.ht-advice.adv-warn   { color:#b45309; }

/* ── Daily tiles ────────────────────────────────────────────────────── */
.daily-tile-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:28px; }
.daily-tile { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:22px 24px; box-shadow:var(--shadow); border-top:4px solid var(--border-dk); }
.daily-tile.dd-storm { border-top-color:var(--red); }
.daily-tile.dd-rain  { border-top-color:var(--blue); }
.daily-tile.dd-wind  { border-top-color:#d97706; }
.daily-tile.dd-clear { border-top-color:var(--green); }
.dd-label { font-size:.76rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--ink-4); margin-bottom:5px; }
.dd-cond  { font-size:1rem; font-weight:700; color:var(--ink); margin-bottom:14px; }
.dd-row   { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid var(--bg); font-size:.92rem; }
.dd-row:last-of-type { border-bottom:none; }
.dd-key   { color:var(--ink-3); font-weight:500; }
.dd-val   { font-weight:600; color:var(--ink-2); }
.dd-val.rv-rain  { color:#1741b0; }
.dd-val.rv-heavy { color:var(--red-txt); }
.dd-val.rv-wind  { color:#b45309; }
.dd-val.rv-ok    { color:var(--green-txt); }
.dd-advice { margin-top:13px; padding:10px 13px; border-radius:var(--radius); font-size:.86rem; font-weight:600; line-height:1.55; border:1px solid; }
.adv-skip { background:var(--blue-bg);  border-color:var(--blue-bdr);  color:#1e3a8a; }
.adv-warn { background:var(--amber-bg); border-color:var(--amber-bdr); color:var(--amber-txt); }
.adv-ok   { background:var(--green-bg); border-color:var(--green-bdr); color:var(--green-txt); }

@media(max-width:900px){ .relay-tile-grid{grid-template-columns:1fr} .wx-tile-grid{grid-template-columns:repeat(3,1fr)} .hourly-tile-grid{grid-template-columns:repeat(3,1fr)} }
@media(max-width:700px){ .sensor-grid{grid-template-columns:1fr} .wx-tile-grid{grid-template-columns:repeat(2,1fr)} .hourly-tile-grid{grid-template-columns:repeat(2,1fr)} .daily-tile-grid{grid-template-columns:1fr} }
</style>
</head>
<meta http-equiv="refresh" content="15">
<body>

<!-- ══ OFFLINE POPUP ═════════════════════════════════════════════════ -->
<?php if (!empty($offlineDevices)): ?>
<div class="offline-popup" id="offlinePopup">
  <div class="popup-title">Sensor Device Offline</div>
  <div class="popup-msg">
    <?php foreach ($offlineDevices as $od):
      $mAgo = $od['last_seen'] ? round((int)$od['seconds_ago']/60) : null; ?>
    <strong><?= htmlspecialchars($od['display_name']) ?></strong> — no data<?= $mAgo !== null ? ' for '.$mAgo.' min' : ' (never connected)' ?>.<br>
    <?php endforeach; ?>
    Check device is powered on and connected to WiFi.
  </div>
  <button class="popup-close" onclick="document.getElementById('offlinePopup').style.display='none'">Dismiss</button>
</div>
<?php endif; ?>

<?= sharedNav('dashboard') ?>
<div class="page">

<?php if (!$dbOK): ?>
<div class="db-error">Database connection failed.<br><small><?= htmlspecialchars($dbErr ?? '') ?></small></div>
<?php else: ?>

<!-- ══ STATUS BAR ════════════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($latest): ?>
    <span style="background:var(--surface);border:1px solid var(--border);padding:7px 16px;border-radius:20px;font-size:.9rem;color:var(--ink-3)">
      Last reading: <strong style="color:var(--ink)"><?= date('d M Y, H:i:s', strtotime($latest['recorded_at'])) ?> SLT</strong>
    </span>
    <?php endif; ?>
    <?php if ($stats && $stats['total'] > 0): ?>
    <span style="background:var(--surface);border:1px solid var(--border);padding:7px 16px;border-radius:20px;font-size:.9rem;color:var(--ink-3)">
      <?= number_format($stats['total']) ?> readings today
    </span>
    <?php endif; ?>
    <?php if (!$wxFailed && $wxCurrent): ?>
    <span style="background:var(--surface);border:1px solid var(--border);padding:7px 16px;border-radius:20px;font-size:.9rem;color:var(--ink-3)">
      Outside: <strong><?= htmlspecialchars(weatherDesc($wxCurrent['weather_code'])) ?></strong>, <?= $wxCurrent['temp'] ?> C
    </span>
    <?php endif; ?>
    <!-- CH1 quick chip -->
    <span style="background:<?= $ch1On?'#dbeafe':'var(--surface)' ?>;border:1px solid <?= $ch1On?'#93c5fd':'var(--border)' ?>;padding:7px 14px;border-radius:20px;font-size:.88rem;font-weight:<?= $ch1On?'600':'400' ?>;color:<?= $ch1On?'#1e3a8a':'var(--ink-3)' ?>">
      Sprinkler: <strong><?= $ch1On ? 'ON' : 'OFF' ?></strong><?= $ch1On ? ' '.$humNow.'% hum' : '' ?>
    </span>
    <!-- CH2 quick chip -->
    <span style="background:<?= $ch2On?'#fee2e2':'var(--surface)' ?>;border:1px solid <?= $ch2On?'#f87171':'var(--border)' ?>;padding:7px 14px;border-radius:20px;font-size:.88rem;font-weight:<?= $ch2On?'600':'400' ?>;color:<?= $ch2On?'#7f1d1d':'var(--ink-3)' ?>">
      Cooling: <strong><?= $ch2On ? 'ON' : 'OFF' ?></strong><?= $ch2On ? ' '.$tempNow.' C' : '' ?>
    </span>
  </div>
  <span style="font-size:.84rem;color:var(--ink-4)">Auto-refresh every 15 seconds</span>
</div>

<!-- ══ DEVICE CHIPS ══════════════════════════════════════════════════ -->
<?php if (!empty($allDevices)): ?>
<div class="device-status-bar">
  <?php foreach ($allDevices as $dev):
    $sAgo   = $dev['last_seen'] ? (int)$dev['seconds_ago'] : PHP_INT_MAX;
    $online = $sAgo < SENSOR_TIMEOUT_SEC; $cc = $online ? 'online' : 'offline';
    if ($sAgo<60) $tStr='Just now'; elseif($sAgo<3600) $tStr=round($sAgo/60).' min ago';
    elseif($dev['last_seen']) $tStr=date('H:i',strtotime($dev['last_seen'])); else $tStr='Never'; ?>
  <div class="dev-chip <?= $cc ?>">
    <span class="dev-chip-dot <?= $cc ?>"></span>
    <span class="dev-chip-name"><?= htmlspecialchars($dev['display_name']) ?></span>
    <span class="dev-chip-time <?= !$online?'offline-time':'' ?>"><?= $tStr ?></span>
  </div>
  <?php endforeach; ?>
  <?php if (isOwner()): ?>
  <a href="devices.php" style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:6px 14px;font-size:.88rem;color:var(--ink-3);text-decoration:none;box-shadow:var(--shadow)">Manage Devices</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ 1. ALL ALERTS ═════════════════════════════════════════════════ -->
<div class="sec-head"><span class="sec-title">Alerts</span><span class="sec-meta">Sensors, relays, devices and weather — combined</span></div>
<div class="all-alerts">
  <?php foreach ($allAlerts as [$lvl,$tag,$msg]): ?>
  <div class="a-alert <?= $lvl ?>"><span class="a-tag"><?= htmlspecialchars($tag) ?></span><?= htmlspecialchars($msg) ?></div>
  <?php endforeach; ?>
  <?php if (empty($allAlerts)): ?>
  <div class="a-alert ok"><span class="a-tag">ALL NORMAL</span>All devices online, readings within safe range, both relays off, weather calm.</div>
  <?php endif; ?>
</div>

<!-- ══ 2. SENSOR READING TILES ═══════════════════════════════════════ -->
<?php if ($latest):
  $ts=$latest['temp_status']; $hs=$latest['hum_status']; $gs=$latest['gas_status'];
  $latestDevice=$latest['device_id']??'DEFAULT'; $latestDevName=$latestDevice;
  foreach ($allDevices as $dv) { if($dv['device_id']===$latestDevice){$latestDevName=$dv['display_name'];break;} }
?>
<div class="sec-head">
  <span class="sec-title">Greenhouse Readings</span>
  <span class="sec-meta">From <?= htmlspecialchars($latestDevName) ?> &mdash; <?= date('H:i:s', strtotime($latest['recorded_at'])) ?> SLT</span>
</div>
<div class="sensor-grid">
  <div class="sensor-card <?= stClass($ts) ?>">
    <div class="sc-label">Inside Temperature</div>
    <div class="sc-value"><?= $latest['temperature'] ?></div>
    <div class="sc-unit">Degrees Celsius</div>
    <div class="sc-device"><?= htmlspecialchars($latestDevName) ?></div>
    <span class="status-pill <?= pillClass($ts) ?>"><?= $ts ?></span>
  </div>
  <div class="sensor-card <?= stClass($hs) ?>">
    <div class="sc-label">Inside Humidity</div>
    <div class="sc-value"><?= $latest['humidity'] ?></div>
    <div class="sc-unit">Percent (%)</div>
    <div class="sc-device"><?= htmlspecialchars($latestDevName) ?></div>
    <span class="status-pill <?= pillClass($hs) ?>"><?= $hs ?></span>
  </div>
  <div class="sensor-card <?= stClass($gs) ?>">
    <div class="sc-label">Air Quality</div>
    <div class="sc-value"><?= $latest['gas'] ?></div>
    <div class="sc-unit">Gas level reading</div>
    <div class="sc-device"><?= htmlspecialchars($latestDevName) ?></div>
    <span class="status-pill <?= pillClass($gs) ?>"><?= $gs ?></span>
  </div>
</div>
<?php else: ?>
<div class="empty-state"><div class="e-label">No sensor readings yet</div>
  <p>No data from any device.<?php if(isOwner()):?> <a href="manual.php">Add a reading manually</a>.<?php endif;?></p>
</div>
<?php endif; ?>

<!-- ══ 3. DUAL RELAY TILES ════════════════════════════════════════════ -->
<?php
// CH1 state
$ch1State = $ch1On ? 'running' : ($ch1PendingSec > 0 ? 'pending' : 'off');
// CH2 state
$ch2State = $ch2On ? 'running' : ($ch2PendingSec > 0 ? 'pending' : 'off');

// Humidity bar (0–100 % scale, threshold line at HUM_THRESHOLD %)
$humBarPct   = min(100, round($humNow));
$humThreshPct= MOTOR_HUM_THRESHOLD;   // position of threshold marker
$humAbove    = $humNow > MOTOR_HUM_THRESHOLD;

// Temperature bar (0–50 °C scale, threshold line at MOTOR_TEMP_THRESHOLD °C)
$tempScale   = 50;  // max °C on bar
$tempBarPct  = min(100, round(($tempNow / $tempScale) * 100));
$tempThreshPct = round((MOTOR_TEMP_THRESHOLD / $tempScale) * 100);
$tempAbove   = $tempNow > MOTOR_TEMP_THRESHOLD;

$ch1OnCount = (int)($stats['ch1_on_count'] ?? 0);
$ch2OnCount = (int)($stats['ch2_on_count'] ?? 0);
?>
<div class="sec-head">
  <span class="sec-title">Relay Control</span>
  <span class="sec-meta">HW-383 Dual Channel &mdash; CH1 IN1/D5 sprinkler &mdash; CH2 IN2/D18 cooling fan</span>
</div>
<div class="relay-tile-grid">

  <!-- ── CH1: Sprinkler / Humidity ──────────────────────────────── -->
  <div class="relay-tile ch1-<?= $ch1State ?>">
    <div class="rt-header <?= $ch1On ? 'running-hum' : ($ch1State==='pending' ? 'pending' : 'off') ?>">
      <div class="rt-icon <?= $ch1On ? 'running-hum' : ($ch1State==='pending' ? 'pending' : 'off') ?>">
        <?php if ($ch1On): ?>
          <!-- Water drop icon -->
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6 9 4 13 4 16a8 8 0 0016 0c0-3-2-7-8-14z"/></svg>
        <?php elseif ($ch1State==='pending'): ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <?php else: ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        <?php endif; ?>
      </div>
      <div>
        <div class="rt-state-label <?= $ch1On ? 'running-hum' : ($ch1State==='pending' ? 'pending' : 'off') ?>">
          <?= $ch1On ? 'SPRINKLER ON' : ($ch1State==='pending' ? 'PENDING' : 'SPRINKLER OFF') ?>
        </div>
        <div class="rt-state-sub <?= $ch1On ? 'running-hum' : ($ch1State==='pending' ? 'pending' : 'off') ?>">
          <?= $ch1On ? 'Pump running — '.$humNow.'% humidity' : ($ch1State==='pending' ? 'Triggers in '.fmtSec($ch1PendingSec) : 'Humidity within range') ?>
        </div>
      </div>
      <span class="rt-channel-badge badge-ch1">CH1 / IN1 / D5</span>
    </div>
    <div class="rt-body">
      <!-- Humidity bar -->
      <div class="rt-bar-row">
        <div class="rt-bar-label-row">
          <span class="key">Indoor humidity</span>
          <span class="val <?= $humAbove ? 'above-hum' : 'below' ?>"><?= $humNow ?>% / threshold <?= MOTOR_HUM_THRESHOLD ?>%</span>
        </div>
        <div class="rt-bar-track">
          <div class="rt-bar-fill <?= $humAbove ? 'fill-hum-above' : 'fill-hum-below' ?>" style="width:<?= $humBarPct ?>%"></div>
          <div class="threshold-line" style="left:<?= $humThreshPct ?>%"></div>
        </div>
        <div style="font-size:.74rem;color:<?= $humAbove ? '#1a56db' : 'var(--ink-4)' ?>;font-weight:<?= $humAbove?'600':'400' ?>">
          <?= $humAbove ? 'Above threshold — ' . ($ch1On ? 'sprinkler running' : ('motor triggers in '.fmtSec($ch1PendingSec))) : 'Below threshold — sprinkler off' ?>
        </div>
      </div>
      <!-- Stats -->
      <div class="rt-stats">
        <div class="rt-stat-item">
          <div class="rt-stat-label">Run time</div>
          <div class="rt-stat-value"><?= $ch1On ? fmtSec($ch1RunSec) : '—' ?></div>
          <div class="rt-stat-sub">Current session</div>
        </div>
        <div class="rt-stat-item">
          <div class="rt-stat-label">Active readings today</div>
          <div class="rt-stat-value"><?= $ch1OnCount ?></div>
          <div class="rt-stat-sub">Last 24 hours</div>
        </div>
        <div class="rt-stat-item">
          <div class="rt-stat-label">Trigger rule</div>
          <div class="rt-stat-value" style="font-size:.92rem;color:var(--ink-2)">&gt;<?= MOTOR_HUM_THRESHOLD ?>% × 5 min</div>
          <div class="rt-stat-sub">Stops when humidity normalises</div>
        </div>
        <div class="rt-stat-item">
          <div class="rt-stat-label">Relay pin</div>
          <div class="rt-stat-value" style="font-size:.92rem;color:var(--ink-2)">GPIO5 (D5)</div>
          <div class="rt-stat-sub">IN1 on HW-383, active LOW</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── CH2: Cooling fan / Temperature ─────────────────────────── -->
  <div class="relay-tile ch2-<?= $ch2State ?>">
    <div class="rt-header <?= $ch2On ? 'running-temp' : ($ch2State==='pending' ? 'pending' : 'off') ?>">
      <div class="rt-icon <?= $ch2On ? 'running-temp' : ($ch2State==='pending' ? 'pending' : 'off') ?>">
        <?php if ($ch2On): ?>
          <!-- Thermometer / fan icon -->
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 14.76V3.5a2.5 2.5 0 00-5 0v11.26a4.5 4.5 0 105 0z"/></svg>
        <?php elseif ($ch2State==='pending'): ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <?php else: ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        <?php endif; ?>
      </div>
      <div>
        <div class="rt-state-label <?= $ch2On ? 'running-temp' : ($ch2State==='pending' ? 'pending' : 'off') ?>">
          <?= $ch2On ? 'COOLING FAN ON' : ($ch2State==='pending' ? 'PENDING' : 'COOLING FAN OFF') ?>
        </div>
        <div class="rt-state-sub <?= $ch2On ? 'running-temp' : ($ch2State==='pending' ? 'pending' : 'off') ?>">
          <?= $ch2On ? 'Fan running — '.$tempNow.' C' : ($ch2State==='pending' ? 'Triggers in '.fmtSec($ch2PendingSec) : 'Temperature within range') ?>
        </div>
      </div>
      <span class="rt-channel-badge badge-ch2">CH2 / IN2 / D18</span>
    </div>
    <div class="rt-body">
      <!-- Temperature bar -->
      <div class="rt-bar-row">
        <div class="rt-bar-label-row">
          <span class="key">Indoor temperature</span>
          <span class="val <?= $tempAbove ? 'above-temp' : 'below' ?>"><?= $tempNow ?> C / threshold <?= MOTOR_TEMP_THRESHOLD ?> C</span>
        </div>
        <div class="rt-bar-track">
          <div class="rt-bar-fill <?= $tempAbove ? 'fill-temp-above' : 'fill-temp-below' ?>" style="width:<?= $tempBarPct ?>%"></div>
          <div class="threshold-line" style="left:<?= $tempThreshPct ?>%"></div>
        </div>
        <div style="font-size:.74rem;color:<?= $tempAbove ? '#dc2626' : 'var(--ink-4)' ?>;font-weight:<?= $tempAbove?'600':'400' ?>">
          <?= $tempAbove ? 'Above threshold — ' . ($ch2On ? 'cooling fan running' : ('fan triggers in '.fmtSec($ch2PendingSec))) : 'Below threshold — cooling fan off' ?>
        </div>
      </div>
      <!-- Stats -->
      <div class="rt-stats">
        <div class="rt-stat-item">
          <div class="rt-stat-label">Run time</div>
          <div class="rt-stat-value"><?= $ch2On ? fmtSec($ch2RunSec) : '—' ?></div>
          <div class="rt-stat-sub">Current session</div>
        </div>
        <div class="rt-stat-item">
          <div class="rt-stat-label">Active readings today</div>
          <div class="rt-stat-value"><?= $ch2OnCount ?></div>
          <div class="rt-stat-sub">Last 24 hours</div>
        </div>
        <div class="rt-stat-item">
          <div class="rt-stat-label">Trigger rule</div>
          <div class="rt-stat-value" style="font-size:.92rem;color:var(--ink-2)">&gt;<?= MOTOR_TEMP_THRESHOLD ?> C × 5 min</div>
          <div class="rt-stat-sub">Stops when temperature normalises</div>
        </div>
        <div class="rt-stat-item">
          <div class="rt-stat-label">Relay pin</div>
          <div class="rt-stat-value" style="font-size:.92rem;color:var(--ink-2)">GPIO18 (D18)</div>
          <div class="rt-stat-sub">IN2 on HW-383, active LOW</div>
        </div>
      </div>
    </div>
  </div>

</div><!-- end relay-tile-grid -->

<!-- ══ 4. OUTSIDE WEATHER TILES ══════════════════════════════════════ -->
<?php if (!$wxFailed && $wxCurrent):
  $wCode=$wxCurrent['weather_code']; $wxWind=$wxCurrent['wind']; $wxGust=$wxCurrent['wind_gust'];
  $wxHum=$wxCurrent['humidity']; $wxRain=$wxCurrent['rain_mm']; $wxCloud=$wxCurrent['cloud'];
  $tWind=$wxWind>=WEATHER_WIND_DANGER_KMH?'danger':($wxWind>=WEATHER_WIND_WARNING_KMH?'warn':'ok');
  $tHum=$wxHum>=WEATHER_HUMIDITY_HIGH_PCT?'warn':'ok';
  $tCond=isStormCode($wCode)?'danger':(isRainyCode($wCode)?'info':'ok');
?>
<div class="sec-head"><span class="sec-title">Outside Weather</span><span class="sec-meta"><?= htmlspecialchars(WEATHER_LOCATION) ?> &mdash; updated <?= htmlspecialchars($weather['fetched_at'] ?? '') ?></span></div>
<div class="wx-tile-grid">
  <div class="wx-tile t-neutral">
    <div class="wt-label">Outdoor Temp</div>
    <div class="wt-value"><?= $wxCurrent['temp'] ?></div>
    <div class="wt-unit">Degrees Celsius</div>
    <div class="wt-sub">Feels like <?= $wxCurrent['feels_like'] ?> C. Rain lowers this.</div>
  </div>
  <div class="wx-tile t-<?= $tHum ?>">
    <div class="wt-label">Outdoor Humidity</div>
    <div class="wt-value v-<?= $tHum ?>"><?= $wxHum ?></div>
    <div class="wt-unit">Percent (%)</div>
    <div class="wt-sub"><?= $wxHum>=WEATHER_HUMIDITY_HIGH_PCT?'Close vents — protects inside conditions':'No action needed' ?></div>
    <span class="wt-pill wp-<?= $tHum ?>"><?= $wxHum>=WEATHER_HUMIDITY_HIGH_PCT?'CLOSE VENTS':'NORMAL' ?></span>
  </div>
  <div class="wx-tile t-<?= $tWind ?>">
    <div class="wt-label">Wind Speed</div>
    <div class="wt-value v-<?= $tWind ?>"><?= $wxWind ?></div>
    <div class="wt-unit">km / h</div>
    <div class="wt-sub">Gusts up to <?= $wxGust ?> km/h</div>
    <span class="wt-pill wp-<?= $tWind ?>"><?= $tWind==='danger'?'DANGER':($tWind==='warn'?'WARNING':'NORMAL') ?></span>
  </div>
  <div class="wx-tile t-<?= isRainyCode($wCode)?'info':'ok' ?>">
    <div class="wt-label">Rain</div>
    <div class="wt-value"><?= $wxRain > 0 ? $wxRain : '0' ?></div>
    <div class="wt-unit"><?= $wxRain > 0 ? 'mm now' : 'No rain now' ?></div>
    <div class="wt-sub">Irrigation continues regardless of rain.</div>
    <span class="wt-pill wp-<?= $wxRain>0?'info':'ok' ?>"><?= $wxRain>0?'RAINING':'DRY' ?></span>
  </div>
  <div class="wx-tile t-neutral">
    <div class="wt-label">Cloud Cover</div>
    <div class="wt-value"><?= $wxCloud ?></div>
    <div class="wt-unit">Percent (%)</div>
    <div class="wt-sub"><?= $wxCloud>=80?'Heavily overcast':($wxCloud>=40?'Partly cloudy':'Mostly clear') ?></div>
  </div>
  <div class="wx-tile t-<?= $tCond ?>">
    <div class="wt-label">Conditions</div>
    <div style="font-size:1.05rem;font-weight:700;color:var(--ink);margin-bottom:8px;line-height:1.3"><?= htmlspecialchars(weatherDesc($wCode)) ?></div>
    <div class="wt-sub"><?php
      $ca=[];
      if(isStormCode($wCode))               $ca[]='Close all vents immediately.';
      elseif(isRainyCode($wCode))           $ca[]='Rain outside. Close vents if open.';
      if($wxWind>=WEATHER_WIND_WARNING_KMH) $ca[]='Check fastenings.';
      if($wxHum>=WEATHER_HUMIDITY_HIGH_PCT) $ca[]='Close vents.';
      if(empty($ca))                        $ca[]='Calm — normal operations.';
      echo htmlspecialchars(implode(' ',$ca));
    ?></div>
  </div>
</div>
<?php endif; ?>

<!-- ══ 5. CHARTS ═════════════════════════════════════════════════════ -->
<?php if (count($chart) > 1): ?>
<div class="sec-head"><span class="sec-title">Greenhouse Trends</span><span class="sec-meta">Last <?= count($chart) ?> readings &mdash; <?= date('H:i', strtotime($chart[0]['recorded_at'])) ?> to <?= date('H:i', strtotime(end($chart)['recorded_at'])) ?></span></div>
<div class="charts-grid">
  <div class="chart-card"><div class="chart-title">Temperature (Celsius)</div><div class="chart-wrap"><canvas id="cTemp"></canvas></div></div>
  <div class="chart-card"><div class="chart-title">Humidity (%)</div><div class="chart-wrap"><canvas id="cHum"></canvas></div></div>
  <div class="chart-card full"><div class="chart-title">Air Quality</div><div class="chart-wrap short"><canvas id="cGas"></canvas></div></div>
</div>
<?php endif; ?>

<!-- ══ 6. 24-HOUR SUMMARY ════════════════════════════════════════════ -->
<?php if ($stats && $stats['total'] > 0): ?>
<div class="sec-head"><span class="sec-title">Today's Summary</span><span class="sec-meta"><?= $stats['esp32_count'] ?> automatic + <?= $stats['manual_count'] ?> manual readings in last 24 hours</span></div>
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-head">Temperature</div>
    <div class="stat-row"><span class="stat-key">Average</span><span class="stat-val"><?= $stats['avg_temp'] ?> C</span></div>
    <div class="stat-row"><span class="stat-key">Lowest / Highest</span><span class="stat-val"><?= $stats['min_temp'] ?> / <?= $stats['max_temp'] ?> C</span></div>
    <div class="stat-row"><span class="stat-key">Times above safe range</span><span class="stat-val <?= $stats['temp_warn']>0?'stat-val-warn':'' ?>"><?= $stats['temp_warn'] ?></span></div>
    <div class="stat-row"><span class="stat-key">Times at danger level</span><span class="stat-val <?= $stats['temp_danger']>0?'stat-val-danger':'' ?>"><?= $stats['temp_danger'] ?></span></div>
    <div class="stat-row"><span class="stat-key">Cooling fan active (readings)</span><span class="stat-val <?= $ch2OnCount>0?'stat-val-warn':'' ?>"><?= $ch2OnCount ?></span></div>
  </div>
  <div class="stat-card">
    <div class="stat-head">Humidity</div>
    <div class="stat-row"><span class="stat-key">Average</span><span class="stat-val"><?= $stats['avg_hum'] ?>%</span></div>
    <div class="stat-row"><span class="stat-key">Lowest / Highest</span><span class="stat-val"><?= $stats['min_hum'] ?> / <?= $stats['max_hum'] ?>%</span></div>
    <div class="stat-row"><span class="stat-key">Times outside safe range</span><span class="stat-val <?= $stats['hum_warn']>0?'stat-val-warn':'' ?>"><?= $stats['hum_warn'] ?></span></div>
    <div class="stat-row"><span class="stat-key">Times at danger level</span><span class="stat-val <?= $stats['hum_danger']>0?'stat-val-danger':'' ?>"><?= $stats['hum_danger'] ?></span></div>
    <div class="stat-row"><span class="stat-key">Sprinkler active (readings)</span><span class="stat-val <?= $ch1OnCount>0?'stat-val-warn':'' ?>"><?= $ch1OnCount ?></span></div>
  </div>
  <div class="stat-card">
    <div class="stat-head">Air Quality</div>
    <div class="stat-row"><span class="stat-key">Average reading</span><span class="stat-val"><?= $stats['avg_gas'] ?></span></div>
    <div class="stat-row"><span class="stat-key">Best / Worst</span><span class="stat-val"><?= $stats['min_gas'] ?> / <?= $stats['max_gas'] ?></span></div>
    <div class="stat-row"><span class="stat-key">Times elevated</span><span class="stat-val <?= $stats['gas_warn']>0?'stat-val-warn':'' ?>"><?= $stats['gas_warn'] ?></span></div>
    <div class="stat-row"><span class="stat-key">Times at danger level</span><span class="stat-val <?= $stats['gas_danger']>0?'stat-val-danger':'' ?>"><?= $stats['gas_danger'] ?></span></div>
  </div>
</div>
<?php endif; ?>

<!-- ══ 7. HOURLY FORECAST ════════════════════════════════════════════ -->
<?php if (!$wxFailed && !empty($wxNext6)): ?>
<div class="sec-head"><span class="sec-title">Weather — Next 6 Hours</span><span class="sec-meta">Structural risk outlook</span></div>
<div class="hourly-tile-grid">
<?php foreach ($wxNext6 as $hx):
  $hw=$hx['wind']; $hg=$hx['wind_gust']; $rp=$hx['rain_prob'];
  $htCls='ht-ok';
  if ($hw>=WEATHER_WIND_DANGER_KMH||$hg>=WEATHER_WIND_DANGER_KMH||isStormCode($hx['weather_code'])) $htCls='ht-danger';
  elseif ($hw>=WEATHER_WIND_WARNING_KMH||$hg>=WEATHER_WIND_WARNING_KMH) $htCls='ht-warn';
  elseif (isRainyCode($hx['weather_code'])) $htCls='ht-info';
  $fillCls=$rp>=70?'rfill-danger':($rp>=40?'rfill-warn':'rfill-info');
  $wCls=($hw>=WEATHER_WIND_DANGER_KMH||$hg>=WEATHER_WIND_DANGER_KMH)?'s-danger':(($hw>=WEATHER_WIND_WARNING_KMH||$hg>=WEATHER_WIND_WARNING_KMH)?'s-warn':'');
?>
  <div class="hourly-tile <?= $htCls ?>">
    <div class="ht-time"><?= $hx['time'] ?></div>
    <div class="ht-temp"><?= $hx['temp'] ?> C</div>
    <div class="ht-cond"><?= htmlspecialchars(weatherDesc($hx['weather_code'])) ?></div>
    <div class="ht-rainbar"><div class="ht-rainbar-fill <?= $fillCls ?>" style="width:<?= min(100,$rp) ?>%"></div></div>
    <div class="ht-stat">Rain <?= $rp ?>%<?= $hx['rain_mm']>0?' — '.$hx['rain_mm'].' mm':'' ?></div>
    <div class="ht-stat <?= $wCls ?>" style="margin-top:3px">Wind <?= $hw ?> / <?= $hg ?> km/h</div>
    <div class="ht-stat" style="margin-top:3px">Hum <?= $hx['humidity'] ?>%</div>
    <?php if ($hw>=WEATHER_WIND_DANGER_KMH||isStormCode($hx['weather_code'])): ?>
    <div class="ht-advice adv-danger">Secure covers</div>
    <?php elseif ($hw>=WEATHER_WIND_WARNING_KMH): ?>
    <div class="ht-advice adv-warn">Check fastenings</div>
    <?php elseif (isRainyCode($hx['weather_code'])): ?>
    <div class="ht-advice" style="color:var(--blue);font-size:.68rem;font-weight:700;margin-top:5px;text-transform:uppercase">Close vents</div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ 8. 3-DAY FORECAST ═════════════════════════════════════════════ -->
<?php if (!$wxFailed && !empty($wxDaily)): ?>
<div class="sec-head"><span class="sec-title">3-Day Weather Outlook</span><span class="sec-meta">Structural and ventilation planning</span></div>
<div class="daily-tile-grid">
<?php foreach ($wxDaily as $day):
  $dRain=$day['rain_sum']; $dWind=$day['wind_max']; $dGust=$day['gust_max']; $dCode=$day['weather_code'];
  $ddCls='dd-clear';
  if(isStormCode($dCode))$ddCls='dd-storm';
  elseif($dWind>=WEATHER_WIND_WARNING_KMH)$ddCls='dd-wind';
  elseif($dRain>5)$ddCls='dd-rain';
  $windValCls=$dWind>=WEATHER_WIND_DANGER_KMH?'rv-heavy':($dWind>=WEATHER_WIND_WARNING_KMH?'rv-wind':'');
  $advCls='adv-ok'; $advText='Normal operations. Irrigation continues as planned.';
  if(isStormCode($dCode)){$advCls='adv-warn';$advText='Storm forecast — secure all covers and fastenings.';}
  elseif($dWind>=WEATHER_WIND_DANGER_KMH){$advCls='adv-warn';$advText='Very high wind — secure greenhouse before this day.';}
  elseif($dWind>=WEATHER_WIND_WARNING_KMH){$advCls='adv-warn';$advText='Windy — check fastenings and covers.';}
  elseif($dRain>0){$advCls='adv-skip';$advText='Rain forecast ('.$dRain.' mm). Close vents. Irrigation continues as normal.';}
?>
  <div class="daily-tile <?= $ddCls ?>">
    <div class="dd-label"><?= htmlspecialchars($day['label']) ?></div>
    <div class="dd-cond"><?= htmlspecialchars(weatherDesc($dCode)) ?></div>
    <div class="dd-row"><span class="dd-key">Temperature</span><span class="dd-val"><?= $day['max_temp'] ?> / <?= $day['min_temp'] ?> C</span></div>
    <div class="dd-row"><span class="dd-key">Rain</span><span class="dd-val <?= $dRain>5?'rv-rain':'rv-ok' ?>"><?= $dRain>0?$dRain.' mm ('.$day['rain_prob'].'%)':'None' ?></span></div>
    <div class="dd-row"><span class="dd-key">Wind / Gusts</span><span class="dd-val <?= $windValCls ?>"><?= $dWind ?> / <?= $dGust ?> km/h</span></div>
    <div class="dd-row"><span class="dd-key">Sunrise / Sunset</span><span class="dd-val"><?= $day['sunrise'] ?> / <?= $day['sunset'] ?></span></div>
    <div class="dd-advice <?= $advCls ?>"><?= htmlspecialchars($advText) ?></div>
  </div>
<?php endforeach; ?>
</div>
<div style="text-align:center;font-size:.8rem;color:var(--ink-4);margin-bottom:24px">
  Weather from Open-Meteo (free, no API key) — <?= htmlspecialchars(WEATHER_LOCATION) ?> — cached 15 min
</div>
<?php endif; ?>

<!-- Footer -->
<div style="text-align:center;padding:24px 0 0;border-top:1px solid var(--border);margin-top:8px;font-size:.92rem;color:var(--ink-3)">
  <a href="log.php" style="color:var(--ink-3)">View All Records</a>
  <?php if (isOwner()): ?>
  &nbsp;&middot;&nbsp; <a href="devices.php" style="color:var(--ink-3)">Devices</a>
  &nbsp;&middot;&nbsp; <a href="manual.php" style="color:var(--ink-3)">Add a Reading</a>
  &nbsp;&middot;&nbsp; <a href="export.php" style="color:var(--ink-3)">Download Data</a>
  &nbsp;&middot;&nbsp; <a href="weekly_report.php" style="color:var(--ink-3)">Weekly Report</a>
  <?php endif; ?>
</div>

<?php endif; // dbOK ?>
</div>

<?php if (count($chart) > 1): ?>
<script>
Chart.defaults.color='#5a6478'; Chart.defaults.borderColor='#d4d8e1';
Chart.defaults.font.family='"Inter",sans-serif'; Chart.defaults.font.size=12;
var labels=<?= json_encode($chartLabels) ?>;
function mkChart(id,data,color,yMin,yMax){
  new Chart(document.getElementById(id),{type:'line',
    data:{labels:labels,datasets:[{data:data,borderColor:color,backgroundColor:color+'18',borderWidth:2,pointRadius:0,pointHoverRadius:4,tension:0.3,fill:true}]},
    options:{responsive:true,maintainAspectRatio:false,animation:{duration:300},
      plugins:{legend:{display:false},tooltip:{mode:'index',intersect:false}},
      scales:{x:{ticks:{maxTicksLimit:8,maxRotation:0},grid:{color:'#f0f2f5'}},
              y:{min:yMin,max:yMax,grid:{color:'#f0f2f5'}}}}});
}
mkChart('cTemp',<?= json_encode($chartTemp) ?>,'#1a56db',10,45);
mkChart('cHum', <?= json_encode($chartHum)  ?>,'#155e2e',0,100);
mkChart('cGas', <?= json_encode($chartGas)  ?>,'#854d0e',0,4095);
</script>
<?php endif; ?>
</body></html>
