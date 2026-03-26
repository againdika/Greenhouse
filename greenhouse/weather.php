<?php
// ══════════════════════════════════════════════════════════════════════
//  weather.php  —  Open-Meteo Weather Fetcher for Galle, Sri Lanka
//  No API key required. Caches result for 30 minutes in MySQL.
// ══════════════════════════════════════════════════════════════════════

// Galle, Southern Province, Sri Lanka
define('WX_LAT',      '6.0535');
define('WX_LON',      '80.2210');
define('WX_LOCATION', 'Galle, Southern Province');
define('WX_CACHE_MIN', 30);   // cache weather data for 30 minutes

// ── Auto-create weather cache table ───────────────────────────────────
function initWeatherTable(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS weather_cache (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        cache_key    VARCHAR(50)    NOT NULL UNIQUE,
        data_json    MEDIUMTEXT     NOT NULL,
        fetched_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_key (cache_key)
    ) ENGINE=InnoDB');
}

// ── Main function — returns weather array or null on failure ──────────
//  Call this getWeather() from any page to get current + forecast data.

function getWeather(PDO $db): ?array {
    try {
        initWeatherTable($db);

        // Check cache first
        $row = $db->prepare('SELECT data_json, fetched_at FROM weather_cache WHERE cache_key = "galle_wx"');
        $row->execute();
        $cached = $row->fetch();
        if ($cached) {
            $age = (time() - strtotime($cached['fetched_at'])) / 60;
            if ($age < WX_CACHE_MIN) {
                return json_decode($cached['data_json'], true);
            }
        }

        // Fetch fresh data from Open-Meteo
        $url = 'https://api.open-meteo.com/v1/forecast?'
             . 'latitude='    . WX_LAT
             . '&longitude='  . WX_LON
             . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,'
             .                  'precipitation,weather_code,wind_speed_10m,wind_direction_10m'
             . '&hourly=temperature_2m,relative_humidity_2m,precipitation_probability,'
             .          'precipitation,weather_code,wind_speed_10m'
             . '&daily=weather_code,temperature_2m_max,temperature_2m_min,'
             .         'precipitation_sum,precipitation_probability_max,wind_speed_10m_max'
             . '&timezone=Asia%2FColombo'
             . '&forecast_days=3'
             . '&wind_speed_unit=kmh';

        $ctx  = stream_context_create(['http' => ['timeout' => 8]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return getCachedWeatherAnyAge($db); // return stale if fetch fails

        $raw = json_decode($json, true);
        if (!$raw || !isset($raw['current'])) return null;

        $data = parseWeatherData($raw);

        // Save to cache
        $enc = json_encode($data);
        $db->prepare('INSERT INTO weather_cache (cache_key, data_json, fetched_at)
                      VALUES ("galle_wx", :d, NOW())
                      ON DUPLICATE KEY UPDATE data_json = :d, fetched_at = NOW()')
           ->execute([':d' => $enc]);

        return $data;

    } catch (Exception $e) {
        return null;
    }
}

// ── Return stale cache if fresh fetch failed ──────────────────────────
function getCachedWeatherAnyAge(PDO $db): ?array {
    try {
        $row = $db->prepare('SELECT data_json FROM weather_cache WHERE cache_key = "galle_wx"');
        $row->execute();
        $r = $row->fetch();
        return $r ? json_decode($r['data_json'], true) : null;
    } catch (Exception $e) { return null; }
}

// ── Parse raw Open-Meteo response into clean array ────────────────────
function parseWeatherData(array $raw): array {
    $c = $raw['current'];
    $h = $raw['hourly'];
    $d = $raw['daily'];

    // Find current hour index
    $now        = date('Y-m-d\TH:00');
    $hourIndex  = array_search($now, $h['time'] ?? []);
    if ($hourIndex === false) $hourIndex = 0;

    // Next 6 hours precipitation probability
    $nextHours = [];
    for ($i = 0; $i <= 5; $i++) {
        $idx = $hourIndex + $i;
        if (!isset($h['time'][$idx])) break;
        $nextHours[] = [
            'time'         => date('H:i', strtotime($h['time'][$idx])),
            'precip_prob'  => (int)($h['precipitation_probability'][$idx] ?? 0),
            'precip_mm'    => (float)($h['precipitation'][$idx]           ?? 0),
            'temp'         => (float)($h['temperature_2m'][$idx]          ?? 0),
            'humidity'     => (int)($h['relative_humidity_2m'][$idx]      ?? 0),
            'wind_kmh'     => (float)($h['wind_speed_10m'][$idx]          ?? 0),
            'wx_code'      => (int)($h['weather_code'][$idx]              ?? 0),
        ];
    }

    // 3-day daily forecast
    $daily = [];
    for ($i = 0; $i <= 2; $i++) {
        if (!isset($d['time'][$i])) break;
        $daily[] = [
            'date'          => $d['time'][$i],
            'day_label'     => $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : date('D', strtotime($d['time'][$i]))),
            'wx_code'       => (int)($d['weather_code'][$i]                   ?? 0),
            'temp_max'      => (float)($d['temperature_2m_max'][$i]           ?? 0),
            'temp_min'      => (float)($d['temperature_2m_min'][$i]           ?? 0),
            'precip_mm'     => (float)($d['precipitation_sum'][$i]            ?? 0),
            'precip_prob'   => (int)($d['precipitation_probability_max'][$i]  ?? 0),
            'wind_max'      => (float)($d['wind_speed_10m_max'][$i]           ?? 0),
        ];
    }

    return [
        'location'    => WX_LOCATION,
        'fetched_at'  => date('Y-m-d H:i:s'),
        'current'     => [
            'temp'        => (float)$c['temperature_2m'],
            'feels_like'  => (float)$c['apparent_temperature'],
            'humidity'    => (int)  $c['relative_humidity_2m'],
            'precip_mm'   => (float)$c['precipitation'],
            'wind_kmh'    => (float)$c['wind_speed_10m'],
            'wind_dir'    => (int)  $c['wind_direction_10m'],
            'wx_code'     => (int)  $c['weather_code'],
            'description' => wxDescription($c['weather_code']),
            'condition'   => wxCondition($c['weather_code']),
        ],
        'next_hours'  => $nextHours,
        'daily'       => $daily,
        'alerts'      => buildWeatherAlerts($c, $nextHours, $daily),
    ];
}

// ── Build warning alerts based on farmer-selected conditions ──────────
function buildWeatherAlerts(array $current, array $nextHours, array $daily): array {
    $alerts = [];

    // 1. Rain or thunderstorm expected (next 6 hours)
    $maxRainProb = 0; $stormExpected = false;
    foreach ($nextHours as $h) {
        if ($h['precip_prob'] > $maxRainProb) $maxRainProb = $h['precip_prob'];
        if ($h['wx_code'] >= 95) $stormExpected = true;
    }
    if ($stormExpected) {
        $alerts[] = [
            'type'    => 'danger',
            'icon'    => 'STORM',
            'title'   => 'Thunderstorm Expected',
            'message' => 'Thunderstorm forecast within the next 6 hours. Secure greenhouse vents and check drainage.',
        ];
    } elseif ($maxRainProb >= 70) {
        $alerts[] = [
            'type'    => 'warn',
            'icon'    => 'RAIN',
            'title'   => 'Rain Likely — ' . $maxRainProb . '% Probability',
            'message' => 'Significant rainfall expected in the next 6 hours. Consider closing greenhouse vents to prevent excess humidity.',
        ];
    } elseif ($maxRainProb >= 40) {
        $alerts[] = [
            'type'    => 'info',
            'icon'    => 'RAIN',
            'title'   => 'Possible Rain — ' . $maxRainProb . '% Probability',
            'message' => 'Some rainfall possible in the next 6 hours. Monitor greenhouse humidity levels closely.',
        ];
    }

    // 2. High outdoor humidity
    if ($current['relative_humidity_2m'] > 80) {
        $alerts[] = [
            'type'    => 'warn',
            'icon'    => 'HUM',
            'title'   => 'High Outdoor Humidity — ' . $current['relative_humidity_2m'] . '%',
            'message' => 'Outdoor humidity is elevated. Greenhouse humidity may rise. Increase ventilation and watch for fungal disease risk (Cercospora, Fusarium).',
        ];
    }

    // 3. High outdoor temperature
    if ($current['temperature_2m'] > 32) {
        $alerts[] = [
            'type'    => 'danger',
            'icon'    => 'HEAT',
            'title'   => 'High Outdoor Temperature — ' . $current['temperature_2m'] . ' C',
            'message' => 'Outdoor temperature exceeds 32 C. Greenhouse internal temperature may rise significantly. Check ventilation and shading.',
        ];
    }

    // 4. Strong wind
    if ($current['wind_speed_10m'] > 40) {
        $alerts[] = [
            'type'    => 'danger',
            'icon'    => 'WIND',
            'title'   => 'Strong Wind — ' . $current['wind_speed_10m'] . ' km/h',
            'message' => 'Strong winds detected. Secure greenhouse covers and check structural integrity.',
        ];
    } elseif ($current['wind_speed_10m'] > 25) {
        $alerts[] = [
            'type'    => 'warn',
            'icon'    => 'WIND',
            'title'   => 'Moderate Wind — ' . $current['wind_speed_10m'] . ' km/h',
            'message' => 'Moderate winds. Monitor greenhouse covers and vent openings.',
        ];
    }

    return $alerts;
}

// ── WMO weather code → human description ─────────────────────────────
function wxDescription(int $code): string {
    return match(true) {
        $code === 0              => 'Clear sky',
        $code <= 2               => 'Partly cloudy',
        $code === 3              => 'Overcast',
        $code <= 49              => 'Foggy',
        $code <= 59              => 'Drizzle',
        $code <= 69              => 'Rain',
        $code <= 79              => 'Snow / sleet',
        $code <= 84              => 'Rain showers',
        $code <= 94              => 'Hail showers',
        $code <= 99              => 'Thunderstorm',
        default                  => 'Unknown',
    };
}

// ── WMO weather code → condition category ────────────────────────────
function wxCondition(int $code): string {
    return match(true) {
        $code === 0              => 'clear',
        $code <= 3               => 'cloudy',
        $code <= 49              => 'fog',
        $code <= 84              => 'rain',
        $code <= 94              => 'hail',
        $code <= 99              => 'storm',
        default                  => 'cloudy',
    };
}

// ── Wind direction degrees → compass label ────────────────────────────
function windDir(int $deg): string {
    $dirs = ['N','NE','E','SE','S','SW','W','NW'];
    return $dirs[round($deg / 45) % 8];
}
