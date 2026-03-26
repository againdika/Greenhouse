<?php
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/includes.php';
require_once dirname(__FILE__) . '/auth.php';
requireOwner();

$mailerBase = dirname(__FILE__) . '/PHPMailer/src/';
if (!file_exists($mailerBase . 'PHPMailer.php')) {
    die('PHPMailer not found. Place the PHPMailer/src/ folder in your project root.');
}
require_once $mailerBase . 'PHPMailer.php';
require_once $mailerBase . 'SMTP.php';
require_once $mailerBase . 'Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$dateTo    = date('Y-m-d');
$dateFrom  = date('Y-m-d', strtotime('-6 days'));
$weekLabel = date('d M', strtotime($dateFrom)) . ' to ' . date('d M Y', strtotime($dateTo));

$flash = '';
$stats = null; $dailySummary = []; $episodes = []; $topHighs = [];

try {
    $db = getDB();

    $stmt = $db->prepare('
        SELECT COUNT(*) AS total,
            ROUND(AVG(temperature),1) AS avg_temp, ROUND(MIN(temperature),1) AS min_temp, ROUND(MAX(temperature),1) AS max_temp,
            ROUND(AVG(humidity),1)    AS avg_hum,  ROUND(MIN(humidity),1)    AS min_hum,  ROUND(MAX(humidity),1)    AS max_hum,
            ROUND(AVG(gas))           AS avg_gas,  MIN(gas) AS min_gas, MAX(gas) AS max_gas,
            SUM(temp_status="DANGER") AS temp_danger, SUM(temp_status="WARNING") AS temp_warn,
            SUM(hum_status="DANGER")  AS hum_danger,  SUM(hum_status="WARNING")  AS hum_warn,
            SUM(gas_status="DANGER")  AS gas_danger,  SUM(gas_status="WARNING")  AS gas_warn,
            SUM(temp_status="NORMAL" AND hum_status="NORMAL" AND gas_status="NORMAL") AS all_normal
        FROM sensor_log WHERE DATE(recorded_at) BETWEEN :f AND :t
    ');
    $stmt->execute([':f'=>$dateFrom,':t'=>$dateTo]);
    $stats = $stmt->fetch();

    $stmt = $db->prepare('
        SELECT DATE(recorded_at) AS day, COUNT(*) AS records,
            ROUND(MAX(temperature),1) AS max_temp, ROUND(MIN(temperature),1) AS min_temp,
            ROUND(MAX(humidity),1)    AS max_hum,  MAX(gas) AS max_gas,
            SUM(temp_status="DANGER" OR hum_status="DANGER" OR gas_status="DANGER") AS danger_count,
            SUM(temp_status="WARNING" OR hum_status="WARNING" OR gas_status="WARNING") AS warn_count
        FROM sensor_log WHERE DATE(recorded_at) BETWEEN :f AND :t
        GROUP BY DATE(recorded_at) ORDER BY day ASC
    ');
    $stmt->execute([':f'=>$dateFrom,':t'=>$dateTo]);
    $dailySummary = $stmt->fetchAll();

    $stmt = $db->prepare('
        SELECT id, recorded_at, temperature, humidity, gas, temp_status, hum_status, gas_status
        FROM sensor_log
        WHERE DATE(recorded_at) BETWEEN :f AND :t
          AND (temp_status="DANGER" OR hum_status="DANGER" OR gas_status="DANGER")
        ORDER BY recorded_at ASC
    ');
    $stmt->execute([':f'=>$dateFrom,':t'=>$dateTo]);
    $dangerRaw = $stmt->fetchAll();
    $episodes  = groupDangerEpisodes($dangerRaw, 1800);

    $stmt = $db->prepare('SELECT recorded_at, temperature AS value, temp_status AS status FROM sensor_log WHERE DATE(recorded_at) BETWEEN :f AND :t AND temp_status IN ("WARNING","DANGER") ORDER BY temperature DESC LIMIT 5');
    $stmt->execute([':f'=>$dateFrom,':t'=>$dateTo]);
    $topHighs['temp'] = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT recorded_at, humidity AS value, hum_status AS status FROM sensor_log WHERE DATE(recorded_at) BETWEEN :f AND :t AND hum_status IN ("WARNING","DANGER") ORDER BY CASE WHEN humidity > 85 THEN humidity ELSE (100-humidity) END DESC LIMIT 5');
    $stmt->execute([':f'=>$dateFrom,':t'=>$dateTo]);
    $topHighs['hum'] = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT recorded_at, gas AS value, gas_status AS status FROM sensor_log WHERE DATE(recorded_at) BETWEEN :f AND :t AND gas_status IN ("WARNING","DANGER") ORDER BY gas DESC LIMIT 5');
    $stmt->execute([':f'=>$dateFrom,':t'=>$dateTo]);
    $topHighs['gas'] = $stmt->fetchAll();

} catch (Exception $e) {
    $flash = 'danger:Database error: ' . $e->getMessage();
}

// Send email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_report'])) {
    if (MAIL_APP_PASSWORD === '') {
        $flash = 'danger:The App Password has not been set. Please add it to db.php before sending.';
    } else {
        try {
            $html = buildEmailHTML($stats, $dailySummary, $episodes, $topHighs, $weekLabel, $dateFrom, $dateTo);
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_FROM;
            $mail->Password   = MAIL_APP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addAddress(MAIL_RECIPIENT);
            $mail->isHTML(true);
            $mail->Subject = 'Greenhouse Weekly Report — ' . $weekLabel;
            $mail->Body    = $html;
            $mail->AltBody = buildEmailPlain($stats, $dailySummary, $episodes, $weekLabel);
            $mail->send();
            $flash = 'ok:Weekly report sent to ' . MAIL_RECIPIENT . ' for the period ' . $weekLabel . '.';
        } catch (MailException $e) {
            $flash = 'danger:Could not send email: ' . $mail->ErrorInfo;
        }
    }
}

// --- Group consecutive danger readings into episodes (gap > 30 min = new episode)
function groupDangerEpisodes(array $rows, int $gapSec = 1800): array {
    if (empty($rows)) return [];
    $episodes = []; $cur = null;
    foreach ($rows as $r) {
        $ts = strtotime($r['recorded_at']);
        if ($cur === null || ($ts - $cur['last_ts']) > $gapSec) {
            if ($cur) $episodes[] = $cur;
            $cur = ['start'=>$r['recorded_at'],'end'=>$r['recorded_at'],'last_ts'=>$ts,'start_ts'=>$ts,'rows'=>[$r],'sensors'=>[],'peak_temp'=>$r['temperature'],'peak_hum'=>$r['humidity'],'peak_gas'=>$r['gas']];
        } else {
            $cur['end']     = $r['recorded_at'];
            $cur['last_ts'] = $ts;
            $cur['rows'][]  = $r;
            $cur['peak_temp'] = max($cur['peak_temp'], $r['temperature']);
            $cur['peak_hum']  = max($cur['peak_hum'],  $r['humidity']);
            $cur['peak_gas']  = max($cur['peak_gas'],  $r['gas']);
        }
        if ($r['temp_status']==='DANGER') $cur['sensors']['Temperature'] = true;
        if ($r['hum_status'] ==='DANGER') $cur['sensors']['Humidity']    = true;
        if ($r['gas_status'] ==='DANGER') $cur['sensors']['Air Quality'] = true;
    }
    if ($cur) $episodes[] = $cur;
    foreach ($episodes as &$ep) {
        $secs = max(0, $ep['last_ts'] - $ep['start_ts']);
        $ep['duration_min']  = (int)round($secs / 60);
        $ep['sensor_list']   = implode(', ', array_keys($ep['sensors']));
    }
    unset($ep);
    return $episodes;
}

function fmtDuration(int $minutes): string {
    if ($minutes < 1)  return 'Less than 1 minute';
    if ($minutes < 60) return $minutes . ' minute' . ($minutes>1?'s':'');
    $h = intdiv($minutes,60); $m = $minutes % 60;
    return $h . ' hour' . ($h>1?'s':'') . ($m>0 ? ' ' . $m . ' min' : '');
}

// ── HTML email builder ──────────────────────────────────────────────────────
function buildEmailHTML(?array $stats, array $daily, array $episodes, array $topHighs, string $weekLabel, string $dateFrom, string $dateTo): string {
    $totalDangerMin = array_sum(array_column($episodes, 'duration_min'));
    $dangerEp       = count($episodes);
    $normalPct      = $stats && $stats['total'] > 0 ? round($stats['all_normal'] / $stats['total'] * 100) : 100;

    $navy   = '#0c1a36'; $green  = '#155e2e'; $greenL = '#d1fae5';
    $amber  = '#854d0e'; $amberL = '#fef3c7'; $amberB = '#fbbf24';
    $red    = '#7f1d1d'; $redL   = '#fee2e2'; $redB   = '#f87171';
    $ink3   = '#5a6478'; $ink4   = '#8a94a6'; $border = '#d4d8e1'; $bg = '#f0f2f5';

    $h  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    $h .= '<title>Greenhouse Weekly Report</title></head>';
    $h .= '<body style="margin:0;padding:0;background:#f0f2f5;font-family:Inter,Arial,sans-serif;font-size:15px;color:#0f1923">';
    $h .= '<div style="max-width:680px;margin:0 auto;padding:24px 16px">';

    // Header
    $h .= '<div style="background:'.$navy.';border-radius:12px 12px 0 0;padding:32px 36px;text-align:center">';
    $h .= '<div style="font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#7e96b8;margin-bottom:10px">Greenhouse Monitor</div>';
    $h .= '<div style="font-size:24px;font-weight:700;color:#ffffff;margin-bottom:6px">Weekly Greenhouse Report</div>';
    $h .= '<div style="font-size:15px;color:#8da8c8">' . htmlspecialchars($weekLabel) . '</div>';
    $h .= '</div>';

    // Health banner
    $bannerBg  = $dangerEp > 0 ? $redL   : $greenL;
    $bannerBdr = $dangerEp > 0 ? $redB   : '#6ee7b7';
    $bannerTxt = $dangerEp > 0 ? $red    : $green;
    $bannerMsg = $dangerEp > 0
        ? $dangerEp . ' danger period' . ($dangerEp>1?'s':'') . ' were recorded this week — ' . fmtDuration($totalDangerMin) . ' total time at danger level'
        : 'All sensors remained within safe limits throughout the week';
    $h .= '<div style="background:'.$bannerBg.';border:1px solid '.$bannerBdr.';border-left:5px solid '.$bannerTxt.';border-radius:0;padding:16px 28px;font-size:15px;font-weight:600;color:'.$bannerTxt.'">';
    $h .= htmlspecialchars($bannerMsg) . '</div>';

    // Summary section
    $h .= '<div style="background:#fff;border:1px solid '.$border.';border-top:none;padding:28px 32px">';
    $h .= '<div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:'.$ink3.';margin-bottom:18px;padding-bottom:12px;border-bottom:2px solid '.$border.'">Weekly Summary</div>';

    // Stat cards row
    $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:22px"><tr>';
    $cards = [
        ['Total Readings',    number_format($stats['total']??0), ''],
        ['Normal Conditions', $normalPct.'%', $normalPct>=90?$green:($normalPct>=70?$amber:$red)],
        ['Danger Periods',    $dangerEp,      $dangerEp>0?$red:$green],
    ];
    foreach ($cards as $i => [$lbl,$val,$col]) {
        $pr = $i<2?'padding-right:14px':'';
        $h .= '<td style="width:33%;'.$pr.'">';
        $h .= '<div style="background:'.$bg.';border:1px solid '.$border.';border-radius:10px;padding:16px;text-align:center">';
        $h .= '<div style="font-size:22px;font-weight:700;color:'.($col?:$navy).';line-height:1;margin-bottom:6px">' . htmlspecialchars((string)$val) . '</div>';
        $h .= '<div style="font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink4.'">' . htmlspecialchars($lbl) . '</div>';
        $h .= '</div></td>';
    }
    $h .= '</tr></table>';

    // Sensor table
    $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid '.$border.';border-radius:10px;overflow:hidden;margin-bottom:24px">';
    $h .= '<tr style="background:#f7f8fa"><th style="padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:left;border-bottom:2px solid '.$border.'">Sensor</th><th style="padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Average</th><th style="padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Lowest</th><th style="padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Highest</th><th style="padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Warnings</th><th style="padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Dangers</th></tr>';
    $srows = [
        ['Temperature (C)', $stats['avg_temp']??'-', $stats['min_temp']??'-', $stats['max_temp']??'-', $stats['temp_warn']??0, $stats['temp_danger']??0],
        ['Humidity (%)',     $stats['avg_hum'] ??'-', $stats['min_hum'] ??'-', $stats['max_hum'] ??'-', $stats['hum_warn'] ??0, $stats['hum_danger'] ??0],
        ['Air Quality',     $stats['avg_gas'] ??'-', $stats['min_gas'] ??'-', $stats['max_gas'] ??'-', $stats['gas_warn'] ??0, $stats['gas_danger'] ??0],
    ];
    foreach ($srows as $i => $sr) {
        $rbg = $i%2===0?'#fff':'#f7f8fa';
        $bdr = ($i<count($srows)-1)?'border-bottom:1px solid '.$border.';':'';
        $wc  = $sr[4]>0?$amber:$ink3;
        $dc  = $sr[5]>0?$red:$ink3;
        $h .= '<tr style="background:'.$rbg.'">';
        $h .= '<td style="'.$bdr.'padding:11px 16px;font-size:14px;color:#2d3748;font-weight:500">'.$sr[0].'</td>';
        $h .= '<td style="'.$bdr.'padding:11px 16px;font-size:14px;text-align:center">'.$sr[1].'</td>';
        $h .= '<td style="'.$bdr.'padding:11px 16px;font-size:14px;text-align:center">'.$sr[2].'</td>';
        $h .= '<td style="'.$bdr.'padding:11px 16px;font-size:14px;text-align:center;font-weight:700">'.$sr[3].'</td>';
        $h .= '<td style="'.$bdr.'padding:11px 16px;font-size:14px;text-align:center;color:'.$wc.';font-weight:600">'.$sr[4].'</td>';
        $h .= '<td style="'.$bdr.'padding:11px 16px;font-size:14px;text-align:center;color:'.$dc.';font-weight:700">'.$sr[5].'</td>';
        $h .= '</tr>';
    }
    $h .= '</table>';

    // Daily breakdown
    $h .= '<div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:'.$ink3.';margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid '.$border.'">Day by Day</div>';
    if (!empty($daily)) {
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid '.$border.';border-radius:10px;overflow:hidden;margin-bottom:24px">';
        $h .= '<tr style="background:#f7f8fa"><th style="padding:10px 14px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:left;border-bottom:2px solid '.$border.'">Date</th><th style="padding:10px 14px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Readings</th><th style="padding:10px 14px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Max Temp</th><th style="padding:10px 14px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Max Humidity</th><th style="padding:10px 14px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:2px solid '.$border.'">Condition</th></tr>';
        foreach ($daily as $i => $d) {
            $rbg  = $i%2===0?'#fff':'#f7f8fa';
            $last = $i===count($daily)-1;
            $bdr  = !$last?'border-bottom:1px solid '.$border.';':'';
            $hd=$d['danger_count']>0; $hw=$d['warn_count']>0;
            $pc=$hd?$red:($hw?$amber:$green); $pb=$hd?$redL:($hw?$amberL:$greenL);
            $pl=$hd?'DANGER':($hw?'WARNING':'NORMAL');
            $h .= '<tr style="background:'.$rbg.'">';
            $h .= '<td style="'.$bdr.'padding:10px 14px;font-size:14px;color:#0f1923;font-weight:600">' . date('D, d M', strtotime($d['day'])) . '</td>';
            $h .= '<td style="'.$bdr.'padding:10px 14px;font-size:14px;text-align:center">'.$d['records'].'</td>';
            $h .= '<td style="'.$bdr.'padding:10px 14px;font-size:14px;text-align:center">'.$d['max_temp'].' C</td>';
            $h .= '<td style="'.$bdr.'padding:10px 14px;font-size:14px;text-align:center">'.$d['max_hum'].'%</td>';
            $h .= '<td style="'.$bdr.'padding:10px 14px;text-align:center"><span style="background:'.$pb.';color:'.$pc.';border:1px solid '.$pc.';border-radius:5px;padding:3px 10px;font-size:11px;font-weight:700;letter-spacing:.07em">'.$pl.'</span></td>';
            $h .= '</tr>';
        }
        $h .= '</table>';
    }

    // Danger period tiles
    if (!empty($episodes)) {
        $h .= '<div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:'.$red.';margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid '.$redL.'">Danger Periods — Time at Danger Level</div>';
        foreach ($episodes as $idx => $ep) {
            $dur    = fmtDuration($ep['duration_min']);
            $sensor = $ep['sensor_list'] ?: 'Not specified';
            $h .= '<div style="background:'.$redL.';border:1px solid '.$redB.';border-left:5px solid '.$red.';border-radius:10px;padding:18px 22px;margin-bottom:14px">';
            $h .= '<table width="100%" cellpadding="0" cellspacing="0"><tr>';
            $h .= '<td><div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:'.$red.';margin-bottom:6px">Period ' . ($idx+1) . '</div>';
            $h .= '<div style="font-size:14px;font-weight:600;color:#2d3748">' . date('D d M, H:i', strtotime($ep['start']));
            if ($ep['start'] !== $ep['end']) $h .= ' to ' . date('H:i', strtotime($ep['end']));
            $h .= '</div>';
            $h .= '<div style="font-size:13px;color:'.$ink3.';margin-top:4px">Affected: ' . htmlspecialchars($sensor) . '</div></td>';
            $h .= '<td style="text-align:right;vertical-align:middle">';
            $h .= '<div style="background:'.$red.';color:#fff;border-radius:20px;padding:8px 20px;font-size:14px;font-weight:700;display:inline-block;white-space:nowrap">' . htmlspecialchars($dur) . '</div></td></tr></table>';

            // Peak values
            $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;border-top:1px solid '.$redB.';padding-top:12px"><tr>';
            $peaks = [
                ['Highest Temp',     $ep['peak_temp'].' C', tempStatus((float)$ep['peak_temp'])],
                ['Highest Humidity', $ep['peak_hum'].'%',   humStatus((float)$ep['peak_hum'])],
                ['Worst Air Quality',$ep['peak_gas'],        gasStatus((int)$ep['peak_gas'])],
            ];
            foreach ($peaks as $pi => [$pl,$pv,$ps]) {
                $pc = $ps==='DANGER'?$red:($ps==='WARNING'?$amber:$green);
                $pp = $pi<2?'padding-right:12px':'';
                $h .= '<td style="width:33%;'.$pp.'">';
                $h .= '<div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:'.$ink4.';margin-bottom:4px">' . $pl . '</div>';
                $h .= '<div style="font-size:18px;font-weight:700;color:'.$pc.'">' . htmlspecialchars((string)$pv) . '</div>';
                $h .= '<div style="font-size:11px;font-weight:700;color:'.$pc.';letter-spacing:.06em">' . $ps . '</div>';
                $h .= '</td>';
            }
            $h .= '</tr></table></div>';
        }

        // Total danger time summary
        $h .= '<div style="background:#fff8f8;border:1px solid '.$redB.';border-radius:10px;padding:14px 22px;margin-bottom:24px;text-align:center">';
        $h .= '<span style="font-size:14px;color:'.$ink3.'">Total time at DANGER level this week: </span>';
        $h .= '<span style="font-size:18px;font-weight:700;color:'.$red.'">' . fmtDuration($totalDangerMin) . '</span>';
        $h .= ' <span style="font-size:13px;color:'.$ink4.'">across ' . $dangerEp . ' period' . ($dangerEp>1?'s':'') . '</span></div>';
    }

    // Highest readings
    $tileConf = [
        ['temp','Temperature','C','Safe range: 20 to 30 C'],
        ['hum', 'Humidity',   '%','Safe range: 60 to 80%'],
        ['gas', 'Air Quality','', 'Normal: reading below 800'],
    ];
    $anyH = array_filter($topHighs, fn($a)=>!empty($a));
    if (!empty($anyH)) {
        $h .= '<div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:'.$ink3.';margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid '.$border.'">Highest Readings This Week</div>';
        foreach ($tileConf as [$key,$label,$unit,$hint]) {
            if (empty($topHighs[$key])) continue;
            $h .= '<div style="margin-bottom:18px">';
            $h .= '<div style="font-size:12px;font-weight:700;color:'.$ink3.';margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em">' . $label . ' &mdash; <span style="font-weight:500;font-style:italic;text-transform:none">' . $hint . '</span></div>';
            $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid '.$border.';border-radius:10px;overflow:hidden">';
            $h .= '<tr style="background:#f7f8fa"><th style="padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:left;border-bottom:1px solid '.$border.'">Date and Time</th><th style="padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:1px solid '.$border.'">Reading</th><th style="padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:'.$ink3.';text-align:center;border-bottom:1px solid '.$border.'">Condition</th></tr>';
            foreach ($topHighs[$key] as $ri => $row) {
                $rbg  = $ri%2===0?'#fff':'#f7f8fa';
                $last = $ri===count($topHighs[$key])-1;
                $bdr  = !$last?'border-bottom:1px solid '.$border.';':'';
                $sc   = $row['status']==='DANGER'?$red:$amber;
                $sb   = $row['status']==='DANGER'?$redL:$amberL;
                $h .= '<tr style="background:'.$rbg.'">';
                $h .= '<td style="'.$bdr.'padding:10px 14px;font-size:14px;color:#2d3748">' . htmlspecialchars($row['recorded_at']) . ' SLT</td>';
                $h .= '<td style="'.$bdr.'padding:10px 14px;font-size:15px;font-weight:700;color:'.$sc.';text-align:center">' . htmlspecialchars($row['value']) . ($unit?' '.$unit:'') . '</td>';
                $h .= '<td style="'.$bdr.'padding:10px 14px;text-align:center"><span style="background:'.$sb.';color:'.$sc.';border:1px solid '.$sc.';border-radius:5px;padding:3px 10px;font-size:11px;font-weight:700;letter-spacing:.06em">' . htmlspecialchars($row['status']) . '</span></td>';
                $h .= '</tr>';
            }
            $h .= '</table></div>';
        }
    }

    $h .= '</div>';

    // Footer
    $h .= '<div style="background:#f7f8fa;border:1px solid '.$border.';border-top:none;border-radius:0 0 12px 12px;padding:18px 32px;text-align:center">';
    $h .= '<div style="font-size:12px;color:'.$ink4.';line-height:1.9">';
    $h .= 'Greenhouse Monitor &mdash; Chile Cultivation, Sri Lanka<br>';
    $h .= 'Report generated: ' . date('d M Y, H:i') . ' SLT (UTC+05:30)<br>';
    $h .= 'Period covered: ' . htmlspecialchars($dateFrom) . ' to ' . htmlspecialchars($dateTo) . '<br>';
    $h .= 'Safe ranges: Temperature 20 to 30 C &nbsp;|&nbsp; Humidity 60 to 80% &nbsp;|&nbsp; Air Quality below 800';
    $h .= '</div></div></div></body></html>';
    return $h;
}

function buildEmailPlain(?array $stats, array $daily, array $episodes, string $weekLabel): string {
    $lines = ['GREENHOUSE WEEKLY REPORT', $weekLabel, str_repeat('-',40), ''];
    if ($stats) {
        $lines[] = 'SUMMARY';
        $lines[] = 'Total readings  : ' . number_format($stats['total']);
        $lines[] = 'Temperature     : Avg ' . $stats['avg_temp'] . ' | Low ' . $stats['min_temp'] . ' | High ' . $stats['max_temp'];
        $lines[] = 'Humidity        : Avg ' . $stats['avg_hum']  . ' | Low ' . $stats['min_hum']  . ' | High ' . $stats['max_hum'];
        $lines[] = 'Air Quality     : Avg ' . $stats['avg_gas']  . ' | Best ' . $stats['min_gas'] . ' | Worst ' . $stats['max_gas'];
        $lines[] = '';
    }
    if (!empty($episodes)) {
        $lines[] = 'DANGER PERIODS';
        foreach ($episodes as $i => $ep) {
            $lines[] = ($i+1) . '. ' . $ep['start'] . ' to ' . $ep['end'] . ' (' . fmtDuration($ep['duration_min']) . ') — ' . $ep['sensor_list'];
        }
        $lines[] = 'Total time at danger: ' . fmtDuration(array_sum(array_column($episodes,'duration_min')));
    }
    return implode("\n", $lines);
}

// ── Page output ──────────────────────────────────────────────────────────────
echo sharedHead('Weekly Report');
echo sharedCSS();
?>
<style>
.report-grid { display:grid; grid-template-columns:1fr 360px; gap:28px; align-items:start; }
.report-panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow); margin-bottom:22px; }
.report-panel-head { padding:14px 22px; background:#f7f8fa; border-bottom:1px solid var(--border); font-size:.88rem; font-weight:700; color:var(--ink-2); text-transform:uppercase; letter-spacing:.07em; }
.report-panel-head.danger-head { background:var(--red-bg); color:var(--red-txt); }
.report-panel-body { padding:22px; }

/* Danger period tiles */
.danger-tile { background:var(--red-bg); border:1px solid var(--red-bdr); border-left:5px solid var(--red); border-radius:var(--radius-lg); padding:18px 20px; margin-bottom:14px; }
.danger-tile-header { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; margin-bottom:14px; }
.danger-period-num { font-size:.8rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--red-txt); margin-bottom:6px; }
.danger-period-time { font-size:1rem; font-weight:600; color:var(--ink-2); }
.danger-period-sensor { font-size:.9rem; color:var(--ink-3); margin-top:4px; }
.danger-duration { background:var(--red); color:#fff; border-radius:20px; padding:8px 20px; font-size:.95rem; font-weight:700; white-space:nowrap; flex-shrink:0; }
.danger-peaks { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; border-top:1px solid var(--red-bdr); padding-top:14px; }
.peak-label { font-size:.78rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--ink-4); margin-bottom:4px; }
.peak-val   { font-size:1.3rem; font-weight:700; line-height:1; }
.peak-status { font-size:.75rem; font-weight:700; letter-spacing:.06em; margin-top:2px; text-transform:uppercase; }

/* High readings tiles */
.high-tile { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:18px; box-shadow:var(--shadow); }
.high-tile-head { padding:12px 18px; background:#f7f8fa; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
.high-tile-label { font-size:.88rem; font-weight:700; color:var(--ink-2); text-transform:uppercase; letter-spacing:.06em; }
.high-tile-hint  { font-size:.82rem; color:var(--ink-4); }
.high-row { display:flex; align-items:center; justify-content:space-between; padding:11px 18px; border-bottom:1px solid var(--border); font-size:.95rem; }
.high-row:last-child { border-bottom:none; }
.high-val { font-size:1.1rem; font-weight:700; }

/* Send panel */
.send-panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:26px; box-shadow:var(--shadow); position:sticky; top:84px; }
.send-panel-title { font-size:.95rem; font-weight:700; color:var(--ink); margin-bottom:18px; padding-bottom:12px; border-bottom:1px solid var(--border); text-transform:uppercase; letter-spacing:.06em; }
.meta-row { display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--bg); font-size:.95rem; }
.meta-row:last-child { border-bottom:none; }
.meta-key { color:var(--ink-3); font-weight:500; }
.meta-val { color:var(--ink-2); font-weight:600; }

/* Stats overview */
.stat-ov-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px; }
.stat-ov-card { background:var(--bg); border:1px solid var(--border); border-radius:var(--radius); padding:14px; text-align:center; }
.stat-ov-val { font-size:1.7rem; font-weight:700; line-height:1; margin-bottom:5px; }
.stat-ov-lbl { font-size:.76rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:var(--ink-4); }

/* Day summary sidebar */
.day-bar { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--border); font-size:.92rem; }
.day-bar:last-child { border-bottom:none; }
.day-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }

@media(max-width:880px){ .report-grid{ grid-template-columns:1fr; } .send-panel{ position:static; } }
</style>
</head><body>
<?= sharedNav('report') ?>
<div class="page">

<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div class="flash flash-<?= $ft ?>"><?= htmlspecialchars($fm) ?></div>
<?php endif; ?>

<div class="sec-head">
  <span class="sec-title">Weekly Greenhouse Report</span>
  <span class="sec-meta"><?= htmlspecialchars($weekLabel) ?></span>
</div>

<div class="report-grid">

<!-- Left: Report preview -->
<div>

<?php if ($stats && $stats['total'] > 0): ?>

<!-- Overview numbers -->
<div class="report-panel" style="margin-bottom:22px">
  <div class="report-panel-head">This Week at a Glance</div>
  <div class="report-panel-body">
    <div class="stat-ov-grid">
      <div class="stat-ov-card">
        <div class="stat-ov-val"><?= number_format($stats['total']) ?></div>
        <div class="stat-ov-lbl">Total Readings</div>
      </div>
      <div class="stat-ov-card">
        <div class="stat-ov-val" style="color:<?= count($episodes)>0?'var(--red)':'var(--green)' ?>"><?= count($episodes) ?></div>
        <div class="stat-ov-lbl">Danger Periods</div>
      </div>
      <div class="stat-ov-card">
        <div class="stat-ov-val" style="color:var(--amber)"><?= ($stats['temp_warn']+$stats['hum_warn']+$stats['gas_warn']) ?></div>
        <div class="stat-ov-lbl">Total Warnings</div>
      </div>
      <div class="stat-ov-card">
        <div class="stat-ov-val" style="color:var(--red)"><?= ($stats['temp_danger']+$stats['hum_danger']+$stats['gas_danger']) ?></div>
        <div class="stat-ov-lbl">Total Dangers</div>
      </div>
    </div>
    <div class="tbl-wrap">
    <table>
      <thead><tr><th>Sensor</th><th>Average</th><th>Lowest</th><th>Highest</th><th>Warnings</th><th>Dangers</th></tr></thead>
      <tbody>
        <tr>
          <td style="font-weight:600">Temperature</td>
          <td><?= $stats['avg_temp'] ?> C</td>
          <td><?= $stats['min_temp'] ?> C</td>
          <td class="<?= $stats['temp_danger']>0?'td-danger':($stats['temp_warn']>0?'td-warn':'') ?>"><?= $stats['max_temp'] ?> C</td>
          <td class="<?= $stats['temp_warn']>0?'td-warn':'' ?>"><?= $stats['temp_warn'] ?></td>
          <td class="<?= $stats['temp_danger']>0?'td-danger':'' ?>"><?= $stats['temp_danger'] ?></td>
        </tr>
        <tr>
          <td style="font-weight:600">Humidity</td>
          <td><?= $stats['avg_hum'] ?>%</td>
          <td><?= $stats['min_hum'] ?>%</td>
          <td class="<?= $stats['hum_danger']>0?'td-danger':($stats['hum_warn']>0?'td-warn':'') ?>"><?= $stats['max_hum'] ?>%</td>
          <td class="<?= $stats['hum_warn']>0?'td-warn':'' ?>"><?= $stats['hum_warn'] ?></td>
          <td class="<?= $stats['hum_danger']>0?'td-danger':'' ?>"><?= $stats['hum_danger'] ?></td>
        </tr>
        <tr>
          <td style="font-weight:600">Air Quality</td>
          <td><?= $stats['avg_gas'] ?></td>
          <td><?= $stats['min_gas'] ?></td>
          <td class="<?= $stats['gas_danger']>0?'td-danger':($stats['gas_warn']>0?'td-warn':'') ?>"><?= $stats['max_gas'] ?></td>
          <td class="<?= $stats['gas_warn']>0?'td-warn':'' ?>"><?= $stats['gas_warn'] ?></td>
          <td class="<?= $stats['gas_danger']>0?'td-danger':'' ?>"><?= $stats['gas_danger'] ?></td>
        </tr>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- Danger period tiles -->
<?php if (!empty($episodes)): ?>
<div class="report-panel">
  <div class="report-panel-head danger-head">
    Danger Periods &mdash; Total time at danger level: <?= fmtDuration(array_sum(array_column($episodes,'duration_min'))) ?>
  </div>
  <div class="report-panel-body">
  <?php foreach ($episodes as $idx => $ep): ?>
    <div class="danger-tile">
      <div class="danger-tile-header">
        <div>
          <div class="danger-period-num">Period <?= $idx+1 ?></div>
          <div class="danger-period-time">
            <?= date('D d M, H:i', strtotime($ep['start'])) ?>
            <?= $ep['start']!==$ep['end'] ? ' to ' . date('H:i', strtotime($ep['end'])) : '' ?>
          </div>
          <div class="danger-period-sensor">Affected sensors: <?= htmlspecialchars($ep['sensor_list'] ?: 'Not specified') ?></div>
        </div>
        <div class="danger-duration"><?= fmtDuration($ep['duration_min']) ?></div>
      </div>
      <div class="danger-peaks">
        <?php
        $peaks = [
          ['Highest Temp',     $ep['peak_temp'].' C', tempStatus((float)$ep['peak_temp'])],
          ['Highest Humidity', $ep['peak_hum'].'%',   humStatus((float)$ep['peak_hum'])],
          ['Worst Air Quality',$ep['peak_gas'],        gasStatus((int)$ep['peak_gas'])],
        ];
        foreach ($peaks as [$pl,$pv,$ps]):
          $pc = $ps==='DANGER'?'var(--red-txt)':($ps==='WARNING'?'var(--amber-txt)':'var(--green-txt)');
        ?>
        <div>
          <div class="peak-label"><?= $pl ?></div>
          <div class="peak-val" style="color:<?= $pc ?>"><?= htmlspecialchars((string)$pv) ?></div>
          <div class="peak-status" style="color:<?= $pc ?>"><?= $ps ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Highest readings tiles -->
<?php
$tileConf2 = [
    ['temp','Temperature',  'C', 'Safe range: 20 to 30 C'],
    ['hum', 'Humidity',     '%', 'Safe range: 60 to 80%'],
    ['gas', 'Air Quality',  '',  'Normal reading: below 800'],
];
$anyH2 = array_filter($topHighs, fn($a)=>!empty($a));
if (!empty($anyH2)):
?>
<div class="report-panel">
  <div class="report-panel-head">Highest Readings This Week</div>
  <div class="report-panel-body">
  <?php foreach ($tileConf2 as [$key,$label,$unit,$hint]):
    if (empty($topHighs[$key])) continue;
  ?>
  <div class="high-tile">
    <div class="high-tile-head">
      <span class="high-tile-label"><?= $label ?></span>
      <span class="high-tile-hint"><?= $hint ?></span>
    </div>
    <?php foreach ($topHighs[$key] as $row): ?>
    <div class="high-row">
      <span style="color:var(--ink-3)"><?= htmlspecialchars($row['recorded_at']) ?> SLT</span>
      <span class="high-val <?= $row['status']==='DANGER'?'td-danger':($row['status']==='WARNING'?'td-warn':'td-ok') ?>">
        <?= htmlspecialchars($row['value']) ?><?= $unit?' '.$unit:'' ?>
      </span>
      <span class="badge <?= badgeClass($row['status']) ?>"><?= $row['status'] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
  <div class="empty-state">
    <div class="e-label">No readings this week</div>
    <p>No sensor data was found for <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?>.</p>
  </div>
</div>
<?php endif; ?>
</div><!-- end left -->

<!-- Right: Send panel -->
<div>
<div class="send-panel">
  <div class="send-panel-title">Send Email Report</div>
  <div class="meta-row"><span class="meta-key">Send to</span><span class="meta-val"><?= htmlspecialchars(MAIL_RECIPIENT) ?></span></div>
  <div class="meta-row"><span class="meta-key">Period</span><span class="meta-val"><?= htmlspecialchars($weekLabel) ?></span></div>
  <div class="meta-row"><span class="meta-key">Total readings</span><span class="meta-val"><?= number_format($stats['total']??0) ?></span></div>
  <div class="meta-row"><span class="meta-key">Danger periods</span><span class="meta-val" style="color:<?= count($episodes)>0?'var(--red)':'var(--green)' ?>"><?= count($episodes) ?></span></div>
  <?php if (!empty($episodes)): ?>
  <div class="meta-row"><span class="meta-key">Total danger time</span><span class="meta-val" style="color:var(--red)"><?= fmtDuration(array_sum(array_column($episodes,'duration_min'))) ?></span></div>
  <?php endif; ?>

  <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
    <?php if (MAIL_APP_PASSWORD === ''): ?>
    <div class="flash flash-warn" style="margin-bottom:16px;font-size:.9rem">
      The email password has not been configured. Please set <strong>MAIL_APP_PASSWORD</strong> in <strong>db.php</strong>.
    </div>
    <?php endif; ?>
    <form method="POST">
      <button type="submit" name="send_report" value="1"
        class="btn btn-green btn-sm" style="width:100%;justify-content:center;padding:13px;font-size:1rem"
        <?= (MAIL_APP_PASSWORD===''||($stats['total']??0)===0)?'disabled':'' ?>
        onclick="return confirm('Send this report to <?= htmlspecialchars(MAIL_RECIPIENT) ?>?')">
        Send Weekly Report
      </button>
    </form>
    <p style="font-size:.84rem;color:var(--ink-4);margin-top:12px;line-height:1.7">
      Sends a full report by email covering the last 7 days, including any danger periods, the time spent at risk, and the highest readings recorded.
    </p>
  </div>
</div>

<!-- Day by day sidebar -->
<?php if (!empty($dailySummary)): ?>
<div class="card" style="margin-top:18px">
  <div style="font-size:.88rem;font-weight:700;color:var(--ink-2);margin-bottom:14px;text-transform:uppercase;letter-spacing:.06em">Day by Day</div>
  <?php foreach ($dailySummary as $d):
    $hd=$d['danger_count']>0; $hw=$d['warn_count']>0;
    $dc=$hd?'var(--red)':($hw?'var(--amber)':'var(--green)');
  ?>
  <div class="day-bar">
    <span style="font-weight:600;color:var(--ink-2)"><?= date('D d M', strtotime($d['day'])) ?></span>
    <span style="color:var(--ink-3);font-size:.88rem"><?= $d['records'] ?> readings</span>
    <span class="day-dot" style="background:<?= $dc ?>"></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- end right -->

</div><!-- end report-grid -->
</div>
</body></html>
