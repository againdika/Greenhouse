<?php
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/includes.php';
require_once dirname(__FILE__) . '/auth.php';
requireOwner();

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $db = getDB();
        if ($action === 'delete_one') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) { $db->prepare('DELETE FROM sensor_log WHERE id=:id')->execute([':id'=>$id]); $flash='ok:Record '.$id.' has been deleted.'; }
        } elseif ($action === 'bulk_date') {
            $from=$_POST['from']??''; $to=$_POST['to']??'';
            if ($from&&$to&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) {
                $s=$db->prepare('DELETE FROM sensor_log WHERE DATE(recorded_at) BETWEEN :f AND :t');
                $s->execute([':f'=>$from,':t'=>$to]);
                $flash='ok:Deleted '.$s->rowCount().' record(s) between '.$from.' and '.$to.'.';
            } else $flash='danger:Invalid date range.';
        } elseif ($action === 'bulk_status') {
            $status=$_POST['status']??''; $sensor=$_POST['sensor']??'';
            if (in_array($status,['WARNING','DANGER'])&&in_array($sensor,['temp_status','hum_status','gas_status'])) {
                $s=$db->prepare('DELETE FROM sensor_log WHERE '.$sensor.'=:st');
                $s->execute([':st'=>$status]);
                $flash='warn:Deleted '.$s->rowCount().' record(s).';
            } else $flash='danger:Invalid selection.';
        } elseif ($action === 'delete_manual') {
            $s=$db->query('DELETE FROM sensor_log WHERE source="manual"');
            $flash='warn:Deleted '.$s->rowCount().' manual record(s).';
        } elseif ($action === 'truncate_all') {
            if (trim($_POST['confirm_token']??'')==='DELETE ALL DATA') { $db->query('TRUNCATE TABLE sensor_log'); $flash='danger:All records have been permanently deleted.'; }
            else $flash='danger:Confirmation text did not match. No records were deleted.';
        }
    } catch (Exception $e) { $flash='danger:Error: '.$e->getMessage(); }
}

$overview=null; $daily=[]; $recent=[];
try {
    $db=$getDB=getDB();
    $overview=$db->query('SELECT COUNT(*) AS total, SUM(source="esp32") AS from_sensor, SUM(source="manual") AS from_manual, SUM(temp_status="DANGER"||hum_status="DANGER"||gas_status="DANGER") AS total_danger, SUM(temp_status="WARNING"||hum_status="WARNING"||gas_status="WARNING") AS total_warn, MIN(recorded_at) AS first_record, MAX(recorded_at) AS last_record FROM sensor_log')->fetch();
    $daily=$db->query('SELECT DATE(recorded_at) AS day, COUNT(*) AS cnt, SUM(temp_status="DANGER"||hum_status="DANGER"||gas_status="DANGER") AS dangers FROM sensor_log WHERE recorded_at >= NOW() - INTERVAL 7 DAY GROUP BY DATE(recorded_at) ORDER BY day DESC')->fetchAll();
    $recent=$db->query('SELECT * FROM sensor_log ORDER BY id DESC LIMIT 20')->fetchAll();
} catch (Exception $e) {}

echo sharedHead('Manage Data');
echo sharedCSS();
?>
<style>
.overview-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; margin-bottom:24px; }
.ov-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:18px 22px; text-align:center; box-shadow:var(--shadow); }
.ov-val   { font-size:2rem; font-weight:700; line-height:1; margin-bottom:6px; }
.ov-label { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--ink-4); }
.action-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:28px; }
.action-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow); }
.action-head { padding:13px 20px; font-size:.85rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; border-bottom:1px solid var(--border); }
.action-head-warn   { background:var(--amber-bg); color:var(--amber-txt); border-bottom-color:var(--amber-bdr); }
.action-head-danger { background:var(--red-bg);   color:var(--red-txt);   border-bottom-color:var(--red-bdr); }
.action-body { padding:20px; }
.action-body p { font-size:.95rem; color:var(--ink-3); margin-bottom:16px; line-height:1.6; }
.confirm-input { width:100%; padding:10px 14px; border:1.5px solid var(--red-bdr); border-radius:var(--radius); font-family:var(--font); font-size:.95rem; color:var(--ink); background:var(--surface); margin-bottom:12px; }
.confirm-input:focus { outline:none; border-color:var(--red); }
@media(max-width:700px){ .action-grid{ grid-template-columns:1fr; } }
</style>
</head><body>
<?= sharedNav('admin') ?>
<div class="page">

<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div class="flash flash-<?= $ft ?>"><?= htmlspecialchars($fm) ?></div>
<?php endif; ?>

<?php if ($overview): ?>
<div class="sec-head">
  <span class="sec-title">Database Overview</span>
  <span class="sec-meta">greenhouse — sensor_log table</span>
</div>
<div class="overview-grid">
  <div class="ov-card"><div class="ov-val"><?= number_format($overview['total']) ?></div><div class="ov-label">Total Records</div></div>
  <div class="ov-card"><div class="ov-val" style="color:var(--blue)"><?= number_format($overview['from_sensor']) ?></div><div class="ov-label">From Sensor</div></div>
  <div class="ov-card"><div class="ov-val" style="color:#6d28d9"><?= number_format($overview['from_manual']) ?></div><div class="ov-label">Manual Entries</div></div>
  <div class="ov-card"><div class="ov-val" style="color:var(--amber)"><?= number_format($overview['total_warn']) ?></div><div class="ov-label">Warnings</div></div>
  <div class="ov-card"><div class="ov-val" style="color:var(--red)"><?= number_format($overview['total_danger']) ?></div><div class="ov-label">Danger Events</div></div>
</div>

<?php if ($overview['first_record']): ?>
<div class="card" style="margin-bottom:24px">
  <div class="kv-list">
    <span class="kv-key">First record</span><span class="kv-val"><?= $overview['first_record'] ?></span>
    <span class="kv-key">Most recent record</span><span class="kv-val"><?= $overview['last_record'] ?></span>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($daily)): ?>
<div class="sec-head"><span class="sec-title">Last 7 Days</span></div>
<div class="tbl-wrap" style="margin-bottom:26px">
<table>
  <thead><tr><th>Date</th><th>Records</th><th>Danger Events</th></tr></thead>
  <tbody>
  <?php foreach ($daily as $d): ?>
  <tr>
    <td><?= $d['day'] ?></td>
    <td><?= $d['cnt'] ?></td>
    <td class="<?= $d['dangers']>0?'td-danger':'td-ok' ?>"><?= $d['dangers'] ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<div class="sec-head">
  <span class="sec-title">Delete Records</span>
  <span class="sec-meta" style="color:var(--red-txt)">All deletions are permanent and cannot be undone</span>
</div>
<div class="action-grid">
  <div class="action-card">
    <div class="action-head action-head-warn">Delete by Date Range</div>
    <div class="action-body">
      <p>Delete all records that were recorded between two dates.</p>
      <form method="POST">
        <input type="hidden" name="action" value="bulk_date">
        <div class="form-grid" style="margin-bottom:14px">
          <div class="field"><label>From</label><input type="date" name="from" max="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label>To</label><input type="date" name="to" max="<?= date('Y-m-d') ?>"></div>
        </div>
        <button type="submit" class="btn btn-warn btn-sm" onclick="return confirm('Delete all records in this date range? This cannot be undone.')">Delete Selected Range</button>
      </form>
    </div>
  </div>
  <div class="action-card">
    <div class="action-head action-head-warn">Delete by Condition</div>
    <div class="action-body">
      <p>Delete all records that have a specific condition on one sensor.</p>
      <form method="POST">
        <input type="hidden" name="action" value="bulk_status">
        <div class="form-grid" style="margin-bottom:14px">
          <div class="field"><label>Sensor</label>
            <select name="sensor">
              <option value="temp_status">Temperature</option>
              <option value="hum_status">Humidity</option>
              <option value="gas_status">Air Quality</option>
            </select>
          </div>
          <div class="field"><label>Condition</label>
            <select name="status">
              <option value="WARNING">Warning</option>
              <option value="DANGER">Danger</option>
            </select>
          </div>
        </div>
        <button type="submit" class="btn btn-warn btn-sm" onclick="return confirm('Delete all matching records? This cannot be undone.')">Delete Matching Records</button>
      </form>
    </div>
  </div>
  <div class="action-card">
    <div class="action-head action-head-warn">Delete All Manual Entries</div>
    <div class="action-body">
      <p>Remove all records that were entered manually. Automatic sensor records will not be affected.</p>
      <form method="POST">
        <input type="hidden" name="action" value="delete_manual">
        <button type="submit" class="btn btn-warn btn-sm" onclick="return confirm('Delete all manually entered records? This cannot be undone.')">Delete All Manual Entries</button>
      </form>
    </div>
  </div>
  <div class="action-card">
    <div class="action-head action-head-danger">Delete All Records</div>
    <div class="action-body">
      <p>Permanently removes every record in the database. Type the confirmation text below exactly as shown to proceed.</p>
      <form method="POST">
        <input type="hidden" name="action" value="truncate_all">
        <input class="confirm-input" type="text" name="confirm_token" placeholder='Type: DELETE ALL DATA' autocomplete="off">
        <button type="submit" class="btn btn-danger btn-sm">Delete Everything</button>
      </form>
    </div>
  </div>
</div>

<?php if (!empty($recent)): ?>
<div class="sec-head">
  <span class="sec-title">Delete Individual Records — 20 Most Recent</span>
  <a href="log.php" class="btn btn-outline btn-sm">View All Records</a>
</div>
<div class="tbl-wrap">
<table>
  <thead><tr><th>ID</th><th>Date and Time</th><th>Temp</th><th>Humidity</th><th>Air Quality</th><th>Overall</th><th>Source</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $r):
    $worst=($r['temp_status']==='DANGER'||$r['hum_status']==='DANGER'||$r['gas_status']==='DANGER')?'DANGER':(($r['temp_status']==='WARNING'||$r['hum_status']==='WARNING'||$r['gas_status']==='WARNING')?'WARNING':'NORMAL');
    $src = $r['source']??'esp32';
  ?>
  <tr>
    <td class="td-id"><?= $r['id'] ?></td>
    <td><?= $r['recorded_at'] ?></td>
    <td class="<?= tdClass($r['temp_status']) ?>"><?= $r['temperature'] ?></td>
    <td class="<?= tdClass($r['hum_status'])  ?>"><?= $r['humidity'] ?></td>
    <td class="<?= tdClass($r['gas_status'])  ?>"><?= $r['gas'] ?></td>
    <td><span class="badge <?= badgeClass($worst) ?>"><?= $worst ?></span></td>
    <td><span class="badge badge-<?= $src ?>"><?= $src==='esp32'?'Automatic':'Manual' ?></span></td>
    <td>
      <form method="POST" style="display:inline" onsubmit="return confirm('Delete record <?= $r['id'] ?>?')">
        <input type="hidden" name="action" value="delete_one">
        <input type="hidden" name="id" value="<?= $r['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

</div></body></html>
