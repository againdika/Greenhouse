<?php
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/includes.php';
require_once dirname(__FILE__) . '/auth.php';
requireOwner();

$dateFrom = $_GET['from']   ?? date('Y-m-d', strtotime('-7 days'));
$dateTo   = $_GET['to']     ?? date('Y-m-d');
$source   = $_GET['source'] ?? '';
$download = isset($_GET['download']);

$where = ['DATE(recorded_at) BETWEEN :from AND :to'];
$params = [':from'=>$dateFrom,':to'=>$dateTo];
if ($source && in_array($source, ['esp32','manual'])) {
    $where[] = 'source = :src';
    $params[':src'] = $source;
}
$wSQL = 'WHERE ' . implode(' AND ', $where);

if ($download) {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM sensor_log ' . $wSQL . ' ORDER BY id ASC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=greenhouse_' . $dateFrom . '_to_' . $dateTo . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Record No.','Date and Time (SLT)','Temperature (C)','Humidity (%)','Air Quality','Temp Condition','Humidity Condition','Air Condition','Source','Notes']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['recorded_at'], $r['temperature'], $r['humidity'], $r['gas'],
                $r['temp_status'], $r['hum_status'], $r['gas_status'],
                $r['source']==='esp32'?'Automatic':'Manual',
                $r['notes']??''
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['# Total records', count($rows)]);
        fputcsv($out, ['# Date range', $dateFrom . ' to ' . $dateTo]);
        fputcsv($out, ['# Exported on', date('Y-m-d H:i:s') . ' SLT (UTC+05:30)']);
        fputcsv($out, ['# Safe ranges', 'Temperature 20-30 C | Humidity 60-80% | Air Quality normal below 800']);
        fclose($out);
        exit;
    } catch (Exception $e) { die('Export error: ' . $e->getMessage()); }
}

$count = 0;
try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM sensor_log ' . $wSQL);
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

echo sharedHead('Download Data');
echo sharedCSS();
?>
<style>
.export-card { max-width:620px; }
.count-box { background:#f7f8fa; border:1px solid var(--border); border-radius:var(--radius); padding:20px 24px; margin:22px 0; display:flex; align-items:center; justify-content:space-between; gap:18px; }
.count-num { font-size:2.6rem; font-weight:700; color:var(--green); line-height:1; }
.count-meta { font-size:.9rem; color:var(--ink-3); line-height:1.9; }
.dl-btn { display:flex; align-items:center; justify-content:center; width:100%; padding:13px; background:var(--blue); color:#fff; border:none; border-radius:var(--radius); font-family:var(--font); font-size:1rem; font-weight:700; cursor:pointer; text-decoration:none; transition:background .15s; }
.dl-btn:hover { background:#1741b0; color:#fff; text-decoration:none; }
.dl-btn.disabled { opacity:.4; pointer-events:none; }
.info-note { background:var(--blue-bg); border:1px solid var(--blue-bdr); border-left:4px solid var(--blue); border-radius:var(--radius); padding:14px 18px; margin-top:18px; font-size:.9rem; color:#1e3a8a; line-height:1.7; }
</style>
</head><body>
<?= sharedNav('export') ?>
<div class="page-sm">

<div class="sec-head"><span class="sec-title">Download Sensor Data</span></div>

<div class="card export-card">
  <p style="font-size:.95rem;color:var(--ink-3);margin-bottom:24px;line-height:1.7">Select a date range and click Download to save all sensor readings as a spreadsheet file (CSV). This file can be opened in Microsoft Excel or any spreadsheet application.</p>

  <form method="GET" action="export.php">
    <div class="form-grid" style="margin-bottom:16px">
      <div class="field">
        <label>From Date</label>
        <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>To Date</label>
        <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>Source</label>
        <select name="source">
          <option value="">All records</option>
          <option value="esp32"  <?= $source==='esp32' ?'selected':'' ?>>Automatic sensor only</option>
          <option value="manual" <?= $source==='manual'?'selected':'' ?>>Manual entries only</option>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Update Count</button>

    <div class="count-box">
      <div>
        <div style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-4);margin-bottom:8px">Records in selected range</div>
        <div class="count-num"><?= number_format($count) ?></div>
      </div>
      <div class="count-meta">
        <?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?><br>
        Source: <?= $source ? ($source==='esp32'?'Automatic sensor':'Manual entries') : 'All records' ?><br>
        Estimated file size: <?= number_format($count * 0.08, 1) ?> KB
      </div>
    </div>

    <a href="export.php?from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>&source=<?= urlencode($source) ?>&download=1"
       class="dl-btn <?= $count===0?'disabled':'' ?>">
      Download <?= number_format($count) ?> Records as Spreadsheet
    </a>
  </form>

  <div class="info-note">
    <strong>Columns in the file:</strong> Record number, Date and Time, Temperature, Humidity, Air Quality, condition labels for each sensor, source (automatic or manual), and any notes.<br>
    The file also includes a summary row at the bottom with the date range and safe range reference.
  </div>
</div>

</div></body></html>
