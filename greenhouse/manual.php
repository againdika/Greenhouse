<?php
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/includes.php';
require_once dirname(__FILE__) . '/auth.php';
requireOwner();

$flash = ''; $errors = [];
$vals = ['temp'=>'','humidity'=>'','gas'=>'','datetime'=>date('Y-m-d\TH:i'),'notes'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $temp  = trim($_POST['temp']     ?? '');
    $hum   = trim($_POST['humidity'] ?? '');
    $gas   = trim($_POST['gas']      ?? '');
    $dt    = trim($_POST['datetime'] ?? '');
    $notes = trim($_POST['notes']    ?? '');
    $vals  = ['temp'=>$temp,'humidity'=>$hum,'gas'=>$gas,'datetime'=>$dt,'notes'=>$notes];

    if ($temp===''||!is_numeric($temp))               $errors['temp']     = 'Required. Enter a number, for example 25.5';
    elseif((float)$temp<-10||(float)$temp>60)         $errors['temp']     = 'Must be between -10 and 60';
    if ($hum===''||!is_numeric($hum))                 $errors['humidity'] = 'Required. Enter a number, for example 70';
    elseif((float)$hum<0||(float)$hum>100)            $errors['humidity'] = 'Must be between 0 and 100';
    if ($gas===''||!is_numeric($gas))                 $errors['gas']      = 'Required. Enter a number, for example 750';
    elseif((int)$gas<0||(int)$gas>4095)               $errors['gas']      = 'Must be between 0 and 4095';
    if ($dt==='')                                     $errors['datetime'] = 'Required';

    if (empty($errors)) {
        $t=(float)$temp; $h=(float)$hum; $g=(int)$gas;
        $ts=tempStatus($t); $hs=humStatus($h); $gs=gasStatus($g);
        $dtF = date('Y-m-d H:i:s', strtotime($dt));
        try {
            $db   = getDB();
            $stmt = $db->prepare('INSERT INTO sensor_log (recorded_at,temperature,humidity,gas,temp_status,hum_status,gas_status,source,notes) VALUES (:dt,:t,:h,:g,:ts,:hs,:gs,"manual",:notes)');
            $stmt->execute([':dt'=>$dtF,':t'=>$t,':h'=>$h,':g'=>$g,':ts'=>$ts,':hs'=>$hs,':gs'=>$gs,':notes'=>$notes?:null]);
            $id = $db->lastInsertId();
            $flash = 'ok:Reading saved (Record ' . $id . '). Temperature: ' . $t . ' C  |  Humidity: ' . $h . '%  |  Air Quality: ' . $g . '  |  Condition: ' . $ts;
            $vals = ['temp'=>'','humidity'=>'','gas'=>'','datetime'=>date('Y-m-d\TH:i'),'notes'=>''];
        } catch (Exception $e) { $flash = 'danger:Could not save. Database error: ' . $e->getMessage(); }
    }
}

echo sharedHead('Add Reading');
echo sharedCSS();
?>
<style>
.entry-layout { display:grid; grid-template-columns:1fr 300px; gap:24px; align-items:start; }
.ref-box { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow); position:sticky; top:84px; }
.ref-box-head { background:#f7f8fa; border-bottom:1px solid var(--border); padding:14px 20px; font-size:.88rem; font-weight:700; color:var(--ink-2); text-transform:uppercase; letter-spacing:.07em; }
.ref-box-body { padding:18px 20px; }
.ref-group-title { font-size:.8rem; font-weight:700; color:var(--ink-3); text-transform:uppercase; letter-spacing:.07em; padding:12px 0 6px; }
.ref-row { display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px solid var(--bg); font-size:.92rem; }
.ref-row:last-child { border:none; }
.ref-label { color:var(--ink-3); font-weight:500; }
.preview-box { background:#f7f8fa; border:1px solid var(--border); border-radius:var(--radius); padding:16px 18px; margin-top:20px; }
.preview-title { font-size:.82rem; font-weight:700; color:var(--ink-3); text-transform:uppercase; letter-spacing:.07em; margin-bottom:12px; }
.preview-vals { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; text-align:center; }
.pv-num { font-size:1.6rem; font-weight:700; line-height:1; }
.pv-lbl { font-size:.78rem; color:var(--ink-4); margin-top:4px; }
.preview-badges { display:flex; gap:8px; margin-top:14px; justify-content:center; flex-wrap:wrap; }
@media(max-width:800px){ .entry-layout{ grid-template-columns:1fr; } .ref-box{ position:static; } }
</style>
</head><body>
<?= sharedNav('manual') ?>
<div class="page-sm">

<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div class="flash flash-<?= $ft ?>"><?= htmlspecialchars($fm) ?></div>
<?php endif; ?>

<div class="sec-head"><span class="sec-title">Add a Reading</span></div>

<div class="entry-layout">
<div class="card">
  <p style="font-size:.95rem;color:var(--ink-3);margin-bottom:24px;line-height:1.7">Use this form to enter a sensor reading directly. This is useful when the automatic sensor is offline, or to record a manual check. The entry will be marked as manually entered in the records.</p>
  <form method="POST" action="manual.php">
    <div class="form-grid" style="margin-bottom:18px">
      <div class="field">
        <label>Temperature (Celsius)</label>
        <input type="number" name="temp" step="0.1" min="-10" max="60" placeholder="e.g. 25.5" value="<?= htmlspecialchars($vals['temp']) ?>" class="<?= isset($errors['temp'])?'err':'' ?>" oninput="updatePreview()">
        <?php if(isset($errors['temp'])): ?><span class="field-err-msg"><?= $errors['temp'] ?></span><?php endif; ?>
        <span class="field-hint">Safe range: 20 to 30 C</span>
      </div>
      <div class="field">
        <label>Humidity (%)</label>
        <input type="number" name="humidity" step="0.1" min="0" max="100" placeholder="e.g. 70" value="<?= htmlspecialchars($vals['humidity']) ?>" class="<?= isset($errors['humidity'])?'err':'' ?>" oninput="updatePreview()">
        <?php if(isset($errors['humidity'])): ?><span class="field-err-msg"><?= $errors['humidity'] ?></span><?php endif; ?>
        <span class="field-hint">Safe range: 60 to 80%</span>
      </div>
      <div class="field">
        <label>Air Quality Reading</label>
        <input type="number" name="gas" step="1" min="0" max="4095" placeholder="e.g. 750" value="<?= htmlspecialchars($vals['gas']) ?>" class="<?= isset($errors['gas'])?'err':'' ?>" oninput="updatePreview()">
        <?php if(isset($errors['gas'])): ?><span class="field-err-msg"><?= $errors['gas'] ?></span><?php endif; ?>
        <span class="field-hint">Normal: below 800</span>
      </div>
      <div class="field">
        <label>Date and Time (Sri Lanka)</label>
        <input type="datetime-local" name="datetime" value="<?= htmlspecialchars($vals['datetime']) ?>" class="<?= isset($errors['datetime'])?'err':'' ?>">
        <?php if(isset($errors['datetime'])): ?><span class="field-err-msg"><?= $errors['datetime'] ?></span><?php endif; ?>
      </div>
    </div>
    <div class="field" style="margin-bottom:6px">
      <label>Notes (optional)</label>
      <input type="text" name="notes" maxlength="255" placeholder="e.g. After irrigation, manual check, calibration..." value="<?= htmlspecialchars($vals['notes']) ?>">
      <span class="field-hint">Maximum 255 characters</span>
    </div>

    <div class="preview-box" id="previewBox" style="display:none">
      <div class="preview-title">Condition Preview</div>
      <div class="preview-vals">
        <div><div class="pv-num" id="pTemp">-</div><div class="pv-lbl">Temperature</div></div>
        <div><div class="pv-num" id="pHum">-</div><div class="pv-lbl">Humidity</div></div>
        <div><div class="pv-num" id="pGas">-</div><div class="pv-lbl">Air Quality</div></div>
      </div>
      <div class="preview-badges" id="pBadges"></div>
    </div>

    <div style="display:flex;gap:12px;margin-top:24px;align-items:center">
      <button type="submit" class="btn btn-primary">Save Reading</button>
      <a href="log.php" class="btn btn-outline">View Records</a>
      <?php if(!empty($errors)): ?>
      <span style="font-size:.88rem;color:var(--red-txt)">Please fix <?= count($errors) ?> error<?= count($errors)>1?'s':'' ?> above</span>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="ref-box">
  <div class="ref-box-head">Safe Range Reference</div>
  <div class="ref-box-body">
    <div class="ref-group-title">Temperature (Celsius)</div>
    <div class="ref-row"><span class="ref-label text-ok">Normal</span><span>20 to 30</span></div>
    <div class="ref-row"><span class="ref-label text-warn">Warning</span><span>Below 18 or above 32</span></div>
    <div class="ref-row"><span class="ref-label text-danger">Danger</span><span>Below 15 or above 35</span></div>
    <div class="ref-group-title">Humidity (%)</div>
    <div class="ref-row"><span class="ref-label text-ok">Normal</span><span>60 to 80</span></div>
    <div class="ref-row"><span class="ref-label text-warn">Warning</span><span>Below 50 or above 85</span></div>
    <div class="ref-row"><span class="ref-label text-danger">Danger</span><span>Below 40 or above 90</span></div>
    <div class="ref-group-title">Air Quality</div>
    <div class="ref-row"><span class="ref-label text-ok">Normal</span><span>Below 800</span></div>
    <div class="ref-row"><span class="ref-label text-warn">Warning</span><span>800 to 1500</span></div>
    <div class="ref-row"><span class="ref-label text-danger">Danger</span><span>Above 1500</span></div>
    <div class="divider"></div>
    <p style="font-size:.84rem;color:var(--ink-4);line-height:1.7">The condition is calculated automatically based on the values you enter. The record will be marked as manually entered.</p>
  </div>
</div>
</div>
</div>
<script>
var colors = { NORMAL:'#155e2e', WARNING:'#854d0e', DANGER:'#7f1d1d' };
var badgeMap = { NORMAL:'badge-ok', WARNING:'badge-warn', DANGER:'badge-danger' };
var badgeLbl = { NORMAL:'Normal', WARNING:'Warning', DANGER:'Danger' };
function classify(v,dlo,wlo,whi,dhi){ if(v<dlo||v>dhi)return'DANGER'; if(v<wlo||v>whi)return'WARNING'; return'NORMAL'; }
function classifyGas(v){ if(v>1500)return'DANGER'; if(v>800)return'WARNING'; return'NORMAL'; }
function updatePreview(){
  var t=parseFloat(document.querySelector('[name=temp]').value);
  var h=parseFloat(document.querySelector('[name=humidity]').value);
  var g=parseInt(document.querySelector('[name=gas]').value);
  if(isNaN(t)&&isNaN(h)&&isNaN(g)){ document.getElementById('previewBox').style.display='none'; return; }
  document.getElementById('previewBox').style.display='block';
  var ts=!isNaN(t)?classify(t,15,18,32,35):'--';
  var hs=!isNaN(h)?classify(h,40,50,85,90):'--';
  var gs=!isNaN(g)?classifyGas(g):'--';
  document.getElementById('pTemp').textContent=isNaN(t)?'-':t.toFixed(1);
  document.getElementById('pHum').textContent=isNaN(h)?'-':h.toFixed(1);
  document.getElementById('pGas').textContent=isNaN(g)?'-':g;
  document.getElementById('pTemp').style.color=colors[ts]||'var(--ink-3)';
  document.getElementById('pHum').style.color=colors[hs]||'var(--ink-3)';
  document.getElementById('pGas').style.color=colors[gs]||'var(--ink-3)';
  document.getElementById('pBadges').innerHTML=[['Temperature',ts],['Humidity',hs],['Air Quality',gs]].map(function(x){
    return '<span class="badge '+badgeMap[x[1]]+'">'+x[0]+': '+badgeLbl[x[1]]+'</span>';
  }).join('');
}
</script>
</body></html>
