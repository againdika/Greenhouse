<?php
// ══════════════════════════════════════════════════════════════════════
//  db.php  —  Database, Email, Weather and Device Configuration
// ══════════════════════════════════════════════════════════════════════

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'greenhouse');
define('API_KEY', 'xxxxxxxxxxxx');

// ── Email ──────────────────────────────────────────────────────────────
define('MAIL_FROM',         ''); //email address to send from, e.g. 'noreply@yourdomain.com'
define('MAIL_FROM_NAME',    ''); //name shown in email sender field
define('MAIL_RECIPIENT',    ''); //email address to receive alerts, e.g. 'admin@yourdomain.com'
define('MAIL_APP_PASSWORD', 'xxxxxxxxxx');  // app password for the MAIL_FROM email account (see https://support.google.com/accounts/answer/185833?hl=en for Gmail)

// ── Edge device / sensor ───────────────────────────────────────────────
define('SENSOR_TIMEOUT_SEC', 300);   // 5 min — device shown as offline after this


// CH1: sprinkler pump — activates when humidity exceeds this for 5 min
define('MOTOR_HUM_THRESHOLD',  78);  // %
// CH2: cooling fan   — activates when temperature exceeds this for 5 min
// Chile safe range is 20–30 °C. WARNING starts at 32 °C.
// Relay fires when it is confirmed the temp is above warning level.
define('MOTOR_TEMP_THRESHOLD', 32);  // °C

// ── Weather (Open-Meteo, no API key needed) ────────────────────────────
define('WEATHER_LAT',      '6.0535');
define('WEATHER_LON',      '80.2210');
define('WEATHER_LOCATION', 'Galle, Sri Lanka');
define('WEATHER_TIMEZONE', 'Asia/Colombo');
define('WEATHER_WIND_WARNING_KMH',  25);
define('WEATHER_WIND_DANGER_KMH',   45);
define('WEATHER_HUMIDITY_HIGH_PCT', 88);

// ──────────────────────────────────────────────────────────────────────

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function getWeather(): ?array {
    $cacheFile = sys_get_temp_dir() . '/gh_weather_' . md5(WEATHER_LAT . WEATHER_LON) . '.json';
    $cacheTTL  = 900;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }
    $url = 'https://api.open-meteo.com/v1/forecast'
         . '?latitude='  . urlencode(WEATHER_LAT)
         . '&longitude=' . urlencode(WEATHER_LON)
         . '&timezone='  . urlencode(WEATHER_TIMEZONE)
         . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,'
         .             'precipitation,weather_code,wind_speed_10m,wind_gusts_10m,cloud_cover'
         . '&hourly=temperature_2m,precipitation_probability,precipitation,'
         .         'weather_code,wind_speed_10m,wind_gusts_10m,relative_humidity_2m'
         . '&daily=weather_code,temperature_2m_max,temperature_2m_min,'
         .        'precipitation_sum,precipitation_probability_max,'
         .        'wind_speed_10m_max,wind_gusts_10m_max,sunrise,sunset'
         . '&forecast_days=3';
    $ctx  = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if (!$body) return null;
    $data = json_decode($body, true);
    if (!$data || !isset($data['current'])) return null;
    $c = $data['current']; $h = $data['hourly']; $d = $data['daily'];
    $nowHour = date('Y-m-d\TH:00', time());
    $hourIdx = array_search($nowHour, $h['time'] ?? []);
    if ($hourIdx === false) $hourIdx = 0;
    $next6 = [];
    for ($i = 1; $i <= 6; $i++) {
        $idx = $hourIdx + $i;
        if (!isset($h['time'][$idx])) break;
        $next6[] = ['time'=>date('H:i',strtotime($h['time'][$idx])),
            'temp'=>round($h['temperature_2m'][$idx]??0,1),
            'rain_prob'=>(int)($h['precipitation_probability'][$idx]??0),
            'rain_mm'=>round($h['precipitation'][$idx]??0,1),
            'wind'=>round($h['wind_speed_10m'][$idx]??0,1),
            'wind_gust'=>round($h['wind_gusts_10m'][$idx]??0,1),
            'humidity'=>(int)($h['relative_humidity_2m'][$idx]??0),
            'weather_code'=>(int)($h['weather_code'][$idx]??0)];
    }
    $days3 = [];
    for ($i = 0; $i < min(3, count($d['time']??[])); $i++) {
        $days3[] = ['date'=>$d['time'][$i],
            'label'=>$i===0?'Today':($i===1?'Tomorrow':date('D d M',strtotime($d['time'][$i]))),
            'max_temp'=>round($d['temperature_2m_max'][$i]??0,1),
            'min_temp'=>round($d['temperature_2m_min'][$i]??0,1),
            'rain_sum'=>round($d['precipitation_sum'][$i]??0,1),
            'rain_prob'=>(int)($d['precipitation_probability_max'][$i]??0),
            'wind_max'=>round($d['wind_speed_10m_max'][$i]??0,1),
            'gust_max'=>round($d['wind_gusts_10m_max'][$i]??0,1),
            'weather_code'=>(int)($d['weather_code'][$i]??0),
            'sunrise'=>isset($d['sunrise'][$i])?date('H:i',strtotime($d['sunrise'][$i])):'--',
            'sunset'=>isset($d['sunset'][$i])?date('H:i',strtotime($d['sunset'][$i])):'--'];
    }
    $result = ['fetched_at'=>date('d M Y, H:i').' SLT','location'=>WEATHER_LOCATION,
        'current'=>['temp'=>round($c['temperature_2m']??0,1),'feels_like'=>round($c['apparent_temperature']??0,1),
            'humidity'=>(int)($c['relative_humidity_2m']??0),'rain_mm'=>round($c['precipitation']??0,1),
            'wind'=>round($c['wind_speed_10m']??0,1),'wind_gust'=>round($c['wind_gusts_10m']??0,1),
            'cloud'=>(int)($c['cloud_cover']??0),'weather_code'=>(int)($c['weather_code']??0)],
        'next6'=>$next6,'daily'=>$days3,
        'summary'=>['max_wind_6h'=>max(array_column($next6,'wind')??[0]),
            'max_gust_6h'=>max(array_column($next6,'wind_gust')??[0]),
            'max_rain_6h'=>max(array_column($next6,'rain_mm')??[0])]];
    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

function weatherDesc(int $code): string {
    return match(true) {
        $code===0=>'Clear sky',$code===1=>'Mainly clear',$code===2=>'Partly cloudy',
        $code===3=>'Overcast',in_array($code,[45,48])=>'Foggy',
        in_array($code,[51,53])=>'Light drizzle',$code===55=>'Heavy drizzle',
        in_array($code,[61,63])=>'Light to moderate rain',$code===65=>'Heavy rain',
        in_array($code,[80,81])=>'Light rain showers',$code===82=>'Heavy rain showers',
        $code===95=>'Thunderstorm',in_array($code,[96,99])=>'Thunderstorm with hail',
        default=>'Mixed conditions'};
}
function isRainyCode(int $c): bool { return ($c>=51&&$c<=67)||($c>=80&&$c<=82)||$c>=95; }
function isStormCode(int $c): bool { return $c>=95; }

