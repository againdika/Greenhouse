<?php
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/includes.php';
require_once dirname(__FILE__) . '/auth.php';
requireLogin();

$page    = max(1, (int)($_GET['page']   ?? 1));
$perPage = 50;
$fStatus = $_GET['status'] ?? '';
$fSource = $_GET['source'] ?? '';
$fDate   = $_GET['date']   ?? '';

$where = []; $params = [];
if ($fStatus && in_array($fStatus, ['NORMAL','WARNING','DANGER'])) {
    $where[] = '(temp_status = :st OR hum_status = :st OR gas_status = :st)';
    $params[':st'] = $fStatus;
}
if ($fSource && in_array($fSource, ['esp32','manual'])) {
    $where[] = 'source = :src';
    $params[':src'] = $fSource;
}
if ($fDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDate)) {
    $where[] = 'DATE(recorded_at) = :dt';
    $params[':dt'] = $fDate;
}
$wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = []; $total = 0; $pages = 1; $dbOK = false; $dbErr = '';
try {
    $db  = getDB();
    $cnt = $db->prepare('SELECT COUNT(*) FROM sensor_log ' . $wSQL);
    $cnt->execute($params);
    $total  = (int)$cnt->fetchColumn();
    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare('SELECT id, recorded_at, temperature, humidity, gas, temp_status, hum_status, gas_status, source, notes FROM sensor_log ' . $wSQL . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dbOK = true;
} catch (Exception $e) { $dbErr = $e->getMessage(); }

function qstr(array $ov = []): string {
    global $fStatus, $fSource, $fDate, $page;
    $p = ['status'=>$fStatus,'source'=>$fSource,'date'=>$fDate,'page'=>$page];
    foreach ($ov as $k=>$v) $p[$k] = $v;
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}

echo sharedHead('Sensor Records');
echo sharedCSS();
echo '</style></head><body>';
echo sharedNav('log');
?>
<div class="page">

<?php if (!$dbOK): ?>
<div class="db-error">Could not load records. Database error: <?= htmlspecialchars($dbErr) ?></div>
<?php endif; ?>

<form method="GET" action="log.php">
<div class="filter-bar">
  <div>
    <label>Condition</label>
    <select name="status">
      <option value="">All conditions</option>
      <?php foreach (['NORMAL','WARNING','DANGER'] as $s): ?>
      <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= ucfirst(strtolower($s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Source</label>
    <select name="source">
      <option value="">All sources</option>
      <option value="esp32"  <?= $fSource==='esp32' ?'selected':'' ?>>Automatic sensor</option>
      <option value="manual" <?= $fSource==='manual'?'selected':'' ?>>Entered manually</option>
    </select>
  </div>
  <div>
    <label>Date</label>
    <input type="date" name="date" value="<?= htmlspecialchars($fDate) ?>" max="<?= date('Y-m-d') ?>">
  </div>
  <button type="submit" class="btn btn-primary btn-sm">Apply Filter</button>
  <a href="log.php" class="btn btn-outline btn-sm">Clear</a>
  <span style="margin-left:auto;font-size:.88rem;color:var(--ink-4)">
    <?= number_format($total) ?> records &mdash; Page <?= $page ?> of <?= $pages ?>
  </span>
</div>
</form>

<div class="sec-head">
  <span class="sec-title">Sensor Records</span>
  <?php if (isOwner()): ?>
  <div class="btn-group">
    <a href="manual.php" class="btn btn-outline btn-sm">Add Reading</a>
    <a href="export.php" class="btn btn-primary btn-sm">Download Data</a>
  </div>
  <?php endif; ?>
</div>

<?php if (isHelper()): ?>
<div class="readonly-notice">You are signed in as a Helper. You can view all records but cannot make any changes.</div>
<?php endif; ?>

<div class="tbl-wrap">
<table>
  <thead>
    <tr>
      <th>No.</th>
      <th>Date and Time</th>
      <th>Temperature (C)</th>
      <th>Humidity (%)</th>
      <th>Air Quality</th>
      <th>Temp Condition</th>
      <th>Humidity Condition</th>
      <th>Air Condition</th>
      <th>Source</th>
      <th>Notes</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$dbOK): ?>
    <tr><td colspan="10"><div class="empty-state"><div class="e-label">Could not load records</div><p><?= htmlspecialchars($dbErr) ?></p></div></td></tr>
  <?php elseif (empty($rows)): ?>
    <tr><td colspan="10">
      <div class="empty-state">
        <div class="e-label">No records found</div>
        <p>No data matches your current filter. <a href="log.php">Clear all filters</a> to view all records.</p>
      </div>
    </td></tr>
  <?php else:
    $offset2 = ($page - 1) * $perPage;
    foreach ($rows as $i => $r):
      $rowNum = $total - $offset2 - $i;
      $src    = $r['source'] ?? 'esp32';
  ?>
    <tr>
      <td class="td-id"><?= (int)$rowNum ?></td>
      <td><?= htmlspecialchars((string)($r['recorded_at'] ?? '')) ?></td>
      <td class="<?= tdClass((string)($r['temp_status']??'NORMAL')) ?>"><?= htmlspecialchars((string)($r['temperature']??'')) ?></td>
      <td class="<?= tdClass((string)($r['hum_status'] ??'NORMAL')) ?>"><?= htmlspecialchars((string)($r['humidity']   ??'')) ?></td>
      <td class="<?= tdClass((string)($r['gas_status'] ??'NORMAL')) ?>"><?= htmlspecialchars((string)($r['gas']        ??'')) ?></td>
      <td><span class="badge <?= badgeClass((string)($r['temp_status']??'NORMAL')) ?>"><?= $r['temp_status']??'NORMAL' ?></span></td>
      <td><span class="badge <?= badgeClass((string)($r['hum_status'] ??'NORMAL')) ?>"><?= $r['hum_status'] ??'NORMAL' ?></span></td>
      <td><span class="badge <?= badgeClass((string)($r['gas_status'] ??'NORMAL')) ?>"><?= $r['gas_status'] ??'NORMAL' ?></span></td>
      <td><span class="badge badge-<?= htmlspecialchars($src) ?>"><?= $src==='esp32'?'Automatic':'Manual' ?></span></td>
      <td style="color:var(--ink-3);max-width:180px;overflow:hidden;text-overflow:ellipsis;font-size:.88rem"><?= htmlspecialchars((string)($r['notes']??'')) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div class="pagination">
  <div class="page-links">
    <a class="page-link <?= $page<=1?'disabled':'' ?>" href="<?= qstr(['page'=>$page-1]) ?>">Previous</a>
    <?php
    $from=max(1,$page-3); $to=min($pages,$page+3);
    if ($from>1) echo '<span class="page-link disabled">...</span>';
    for ($p=$from;$p<=$to;$p++):
    ?><a class="page-link <?= $p==$page?'current':'' ?>" href="<?= qstr(['page'=>$p]) ?>"><?= $p ?></a><?php
    endfor;
    if ($to<$pages) echo '<span class="page-link disabled">...</span>';
    ?>
    <a class="page-link <?= $page>=$pages?'disabled':'' ?>" href="<?= qstr(['page'=>$page+1]) ?>">Next</a>
  </div>
  <span class="page-info">Showing <?= ($page-1)*$perPage+1 ?> to <?= min($page*$perPage,$total) ?> of <?= number_format($total) ?> records</span>
</div>
<?php endif; ?>

</div>
</body></html>
