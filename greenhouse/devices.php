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
        if ($action === 'add') {
            $did   = trim($_POST['device_id']    ?? '');
            $name  = trim($_POST['display_name'] ?? '');
            $loc   = trim($_POST['location_desc']?? '');
            $key   = trim($_POST['api_key']      ?? API_KEY);
            if ($did === '' || $name === '') {
                $flash = 'danger:Device ID and Display Name are required.';
            } elseif (!preg_match('/^[A-Za-z0-9_\-]{3,40}$/', $did)) {
                $flash = 'danger:Device ID must be 3 to 40 characters. Letters, numbers, hyphens and underscores only.';
            } else {
                $stmt = $db->prepare('INSERT INTO edge_devices (device_id, display_name, location_desc, api_key) VALUES (:d,:n,:l,:k)');
                $stmt->execute([':d'=>$did,':n'=>$name,':l'=>$loc?:null,':k'=>$key]);
                $flash = 'ok:Device "' . $name . '" registered successfully. Update the Device ID in the ESP32 sketch to match: ' . $did;
            }
        } elseif ($action === 'update') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['display_name'] ?? '');
            $loc  = trim($_POST['location_desc']?? '');
            $key  = trim($_POST['api_key']      ?? '');
            $act  = (int)($_POST['is_active']   ?? 1);
            if ($id && $name) {
                $db->prepare('UPDATE edge_devices SET display_name=:n, location_desc=:l, api_key=:k, is_active=:a WHERE id=:id')
                   ->execute([':n'=>$name,':l'=>$loc?:null,':k'=>$key,':a'=>$act,':id'=>$id]);
                $flash = 'ok:Device updated.';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $db->prepare('DELETE FROM edge_devices WHERE id=:id')->execute([':id'=>$id]);
                $flash = 'ok:Device removed.';
            }
        } elseif ($action === 'toggle') {
            $id  = (int)($_POST['id']        ?? 0);
            $cur = (int)($_POST['is_active'] ?? 1);
            if ($id) {
                $db->prepare('UPDATE edge_devices SET is_active=:a WHERE id=:id')
                   ->execute([':a'=>($cur ? 0 : 1),':id'=>$id]);
                $flash = 'ok:Device ' . ($cur ? 'disabled' : 'enabled') . '.';
            }
        }
    } catch (Exception $e) { $flash = 'danger:' . $e->getMessage(); }
}

$devices = [];
try {
    $devices = getDB()->query('
        SELECT d.*,
               COUNT(s.id) AS total_readings,
               MAX(s.recorded_at) AS last_reading
        FROM edge_devices d
        LEFT JOIN sensor_log s ON s.device_id = d.device_id
        GROUP BY d.id
        ORDER BY d.is_active DESC, d.registered_at ASC
    ')->fetchAll();
} catch (Exception $e) {}

$timeout = SENSOR_TIMEOUT_SEC;

echo sharedHead('Devices');
echo sharedCSS();
?>
<style>
.devices-layout { display: grid; grid-template-columns: 1fr 380px; gap: 28px; align-items: start; }
.device-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius-lg); box-shadow: var(--shadow);
  overflow: hidden; margin-bottom: 16px;
}
.device-card.offline { border-left: 5px solid var(--red); }
.device-card.online  { border-left: 5px solid var(--green); }
.device-card.inactive{ border-left: 5px solid var(--border-dk); opacity: .75; }
.dc-head { display: flex; align-items: center; gap: 16px; padding: 18px 22px; border-bottom: 1px solid var(--border); }
.dc-status-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.dc-status-dot.online  { background: var(--green); }
.dc-status-dot.offline { background: var(--red); }
.dc-status-dot.inactive{ background: var(--border-dk); }
.dc-name   { font-size: 1.05rem; font-weight: 700; color: var(--ink); }
.dc-id     { font-size: .8rem; color: var(--ink-4); margin-top: 2px; }
.dc-badges { display: flex; gap: 8px; margin-left: auto; flex-shrink: 0; flex-wrap: wrap; }
.dc-body   { padding: 18px 22px; }
.dc-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; margin-bottom: 16px; }
.dc-meta-item { font-size: .88rem; }
.dc-meta-label{ font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-4); margin-bottom: 3px; }
.dc-meta-val  { color: var(--ink-2); font-weight: 500; }
.dc-meta-val.offline-val { color: var(--red-txt); font-weight: 700; }
.dc-actions { display: flex; gap: 10px; flex-wrap: wrap; border-top: 1px solid var(--border); padding-top: 14px; }
.inline-form { display: flex; gap: 8px; align-items: center; }
.inline-form input, .inline-form select { padding: 7px 12px; border: 1px solid var(--border-dk); border-radius: var(--radius); font-family: var(--font); font-size: .88rem; color: var(--ink); background: var(--surface); }

/* Register panel */
.reg-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 26px; box-shadow: var(--shadow); position: sticky; top: 84px; }
.reg-title { font-size: .95rem; font-weight: 700; color: var(--ink); margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--border); text-transform: uppercase; letter-spacing: .06em; }
.key-preview { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 14px; font-size: .82rem; color: var(--ink-3); margin-top: 8px; line-height: 1.7; }
.key-preview code { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 2px 6px; font-size: .8rem; color: var(--blue); }

/* Sketch snippet box */
.sketch-box { background: #0c1a36; border-radius: var(--radius-lg); padding: 18px 20px; margin-top: 20px; }
.sketch-box-title { font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #7e96b8; margin-bottom: 12px; }
.sketch-code { font-size: .82rem; color: #c8d8f0; line-height: 1.8; white-space: pre-wrap; word-break: break-all; }
.sketch-code .s-comment { color: #4a6890; }
.sketch-code .s-string  { color: #87ceeb; }
.sketch-code .s-keyword { color: #7eb8f7; }

@media(max-width:900px){ .devices-layout{ grid-template-columns:1fr; } .reg-panel{ position:static; } }
</style>
</head><body>
<?= sharedNav('devices') ?>
<div class="page">

<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div class="flash flash-<?= $ft ?>"><?= htmlspecialchars($fm) ?></div>
<?php endif; ?>

<div class="sec-head">
  <span class="sec-title">Edge Devices</span>
  <span class="sec-meta"><?= count($devices) ?> registered device<?= count($devices)!==1?'s':'' ?> &mdash; offline after <?= round($timeout/60) ?> min silence</span>
</div>

<div class="devices-layout">

<!-- Device list -->
<div>
<?php if (empty($devices)): ?>
<div class="card">
  <div class="empty-state">
    <div class="e-label">No devices registered yet</div>
    <p>Register your first ESP32 device using the form on the right, then update the sketch and re-upload it.</p>
  </div>
</div>
<?php endif; ?>

<?php foreach ($devices as $dev):
  $lastSeen = $dev['last_seen'] ? strtotime($dev['last_seen']) : 0;
  $secAgo   = $lastSeen ? (time() - $lastSeen) : PHP_INT_MAX;
  $isOnline = $dev['is_active'] && $lastSeen && $secAgo < $timeout;
  $isActive = (bool)$dev['is_active'];
  $statusLabel = !$isActive ? 'Disabled' : ($isOnline ? 'Online' : 'Offline');
  $statusCls   = !$isActive ? 'inactive' : ($isOnline ? 'online' : 'offline');
  $dotCls      = $statusCls;

  if ($secAgo < 60)         $agoStr = 'Just now';
  elseif ($secAgo < 3600)   $agoStr = round($secAgo/60) . ' min ago';
  elseif ($secAgo < 86400)  $agoStr = round($secAgo/3600) . ' hr ago';
  elseif ($lastSeen === 0)  $agoStr = 'Never connected';
  else                      $agoStr = date('d M Y, H:i', $lastSeen);
?>
<div class="device-card <?= $statusCls ?>">
  <div class="dc-head">
    <div class="dc-status-dot <?= $dotCls ?>"></div>
    <div>
      <div class="dc-name"><?= htmlspecialchars($dev['display_name']) ?></div>
      <div class="dc-id">ID: <?= htmlspecialchars($dev['device_id']) ?></div>
    </div>
    <div class="dc-badges">
      <span class="badge badge-<?= $isOnline ? 'online' : 'offline' ?>"><?= $statusLabel ?></span>
      <?php if ($dev['total_readings'] > 0): ?>
      <span class="badge badge-esp32"><?= number_format($dev['total_readings']) ?> readings</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="dc-body">
    <div class="dc-meta-grid">
      <div class="dc-meta-item">
        <div class="dc-meta-label">Last data received</div>
        <div class="dc-meta-val <?= !$isOnline && $isActive ? 'offline-val' : '' ?>"><?= $agoStr ?></div>
      </div>
      <div class="dc-meta-item">
        <div class="dc-meta-label">Last IP address</div>
        <div class="dc-meta-val"><?= htmlspecialchars($dev['last_ip'] ?? 'Not yet seen') ?></div>
      </div>
      <div class="dc-meta-item">
        <div class="dc-meta-label">Location</div>
        <div class="dc-meta-val"><?= htmlspecialchars($dev['location_desc'] ?? 'Not specified') ?></div>
      </div>
      <div class="dc-meta-item">
        <div class="dc-meta-label">Registered on</div>
        <div class="dc-meta-val"><?= date('d M Y', strtotime($dev['registered_at'])) ?></div>
      </div>
      <div class="dc-meta-item">
        <div class="dc-meta-label">API key</div>
        <div class="dc-meta-val" style="font-size:.8rem;letter-spacing:.05em"><?= htmlspecialchars(substr($dev['api_key'],0,8)) ?>••••••••</div>
      </div>
      <div class="dc-meta-item">
        <div class="dc-meta-label">Total readings</div>
        <div class="dc-meta-val"><?= number_format($dev['total_readings']) ?></div>
      </div>
    </div>

    <!-- Edit form inline -->
    <form method="POST" style="margin-bottom:12px">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $dev['id'] ?>">
      <input type="hidden" name="is_active" value="<?= $dev['is_active'] ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div class="field">
          <label>Display Name</label>
          <input type="text" name="display_name" value="<?= htmlspecialchars($dev['display_name']) ?>" required>
        </div>
        <div class="field">
          <label>Location Description</label>
          <input type="text" name="location_desc" value="<?= htmlspecialchars($dev['location_desc'] ?? '') ?>" placeholder="e.g. North section, near pump">
        </div>
        <div class="field">
          <label>API Key for this device</label>
          <input type="text" name="api_key" value="<?= htmlspecialchars($dev['api_key']) ?>">
        </div>
      </div>
      <button type="submit" class="btn btn-outline btn-sm">Save Changes</button>
    </form>

    <div class="dc-actions">
      <!-- Toggle active/disabled -->
      <form method="POST">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?= $dev['id'] ?>">
        <input type="hidden" name="is_active" value="<?= $dev['is_active'] ?>">
        <button type="submit" class="btn btn-warn btn-sm">
          <?= $isActive ? 'Disable Device' : 'Enable Device' ?>
        </button>
      </form>
      <!-- Delete -->
      <form method="POST" onsubmit="return confirm('Remove device <?= htmlspecialchars($dev['device_id']) ?>? Sensor log records are kept.')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $dev['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Remove Device</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Registration panel -->
<div>
<div class="reg-panel">
  <div class="reg-title">Register New Device</div>
  <form method="POST" id="regForm">
    <input type="hidden" name="action" value="add">
    <div class="field" style="margin-bottom:14px">
      <label>Device ID</label>
      <input type="text" name="device_id" id="devIdInput" placeholder="e.g. ESP32-ZONE-B" maxlength="40" required oninput="updateSnippet()">
      <span class="field-hint">Unique identifier — letters, numbers, hyphens, underscores. This must match DEVICE_ID in the sketch.</span>
    </div>
    <div class="field" style="margin-bottom:14px">
      <label>Display Name</label>
      <input type="text" name="display_name" placeholder="e.g. Zone B — South Section" maxlength="100" required>
    </div>
    <div class="field" style="margin-bottom:14px">
      <label>Location Description</label>
      <input type="text" name="location_desc" placeholder="e.g. Near the water tank, south wall" maxlength="255">
    </div>
    <div class="field" style="margin-bottom:20px">
      <label>API Key for this device</label>
      <input type="text" name="api_key" value="<?= htmlspecialchars(API_KEY) ?>" id="keyInput" oninput="updateSnippet()">
      <span class="field-hint">You can use the same global key for all devices, or set a unique key per device for extra security.</span>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px">Register Device</button>
  </form>

  <!-- Sketch snippet — updates live as user types -->
  <div class="sketch-box">
    <div class="sketch-box-title">Paste these lines into the ESP32 sketch</div>
    <div class="sketch-code" id="snippetBox"><span class="s-comment">// ── CHANGE THESE IN YOUR SKETCH ──</span>
<span class="s-keyword">const char*</span> DEVICE_ID = <span class="s-string">"<span id="snipId">ESP32-ZONE-B</span>"</span>;
<span class="s-keyword">const char*</span> API_KEY   = <span class="s-string">"<span id="snipKey"><?= htmlspecialchars(API_KEY) ?></span>"</span>;
<span class="s-keyword">const char*</span> API_URL   = <span class="s-string">"http://YOUR-SERVER-IP/greenhouse/api.php"</span>;</div>
  </div>

  <div class="key-preview" style="margin-top:14px">
    Each ESP32 sends <code>device_id</code>, <code>key</code>, <code>temp</code>, <code>humidity</code>, <code>gas</code> in every POST request. The server checks the device is registered and active before accepting any data. Unknown devices are auto-registered so they appear here for you to review.
  </div>
</div>
</div>

</div><!-- end layout -->
</div>

<script>
function updateSnippet() {
  var id  = document.getElementById('devIdInput').value || 'ESP32-ZONE-B';
  var key = document.getElementById('keyInput').value   || '<?= htmlspecialchars(API_KEY) ?>';
  document.getElementById('snipId').textContent  = id;
  document.getElementById('snipKey').textContent = key;
}
</script>
</body></html>
