<?php
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/includes.php';
require_once dirname(__FILE__) . '/auth.php';
requireOwner();

$flash = '';
$me    = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $db = getDB();
        if ($action === 'add_user') {
            $uname = trim($_POST['username'] ?? '');
            $pass  = trim($_POST['password'] ?? '');
            $role  = in_array($_POST['role']??'', ['owner','helper']) ? $_POST['role'] : 'helper';
            $fname = trim($_POST['full_name'] ?? '');
            if ($uname===''||$pass==='') {
                $flash = 'danger:Username and password are both required.';
            } elseif (strlen($pass) < 6) {
                $flash = 'danger:Password must be at least 6 characters.';
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $db->prepare('INSERT INTO users (username,password,role,full_name) VALUES (:u,:p,:r,:n)')->execute([':u'=>$uname,':p'=>$hash,':r'=>$role,':n'=>$fname?:null]);
                $flash = 'ok:User "' . $uname . '" (' . $role . ') has been created.';
            }
        } elseif ($action === 'change_password') {
            $uid  = (int)($_POST['uid'] ?? 0);
            $pass = trim($_POST['new_password'] ?? '');
            if ($uid<1||$pass==='') { $flash='danger:Invalid request.'; }
            elseif (strlen($pass)<6) { $flash='danger:Password must be at least 6 characters.'; }
            else {
                $db->prepare('UPDATE users SET password=:p WHERE id=:id')->execute([':p'=>password_hash($pass,PASSWORD_BCRYPT),':id'=>$uid]);
                $flash = 'ok:Password updated successfully.';
            }
        } elseif ($action === 'change_role') {
            $uid  = (int)($_POST['uid'] ?? 0);
            $role = in_array($_POST['role']??'', ['owner','helper']) ? $_POST['role'] : 'helper';
            if ($uid===(int)$me['id']) { $flash='danger:You cannot change your own role.'; }
            else {
                $db->prepare('UPDATE users SET role=:r WHERE id=:id')->execute([':r'=>$role,':id'=>$uid]);
                $flash = 'ok:Role updated.';
            }
        } elseif ($action === 'delete_user') {
            $uid = (int)($_POST['uid'] ?? 0);
            if ($uid===(int)$me['id']) { $flash='danger:You cannot delete your own account.'; }
            elseif ($uid>0) {
                $db->prepare('DELETE FROM users WHERE id=:id')->execute([':id'=>$uid]);
                $flash = 'ok:User deleted.';
            }
        }
    } catch (Exception $e) { $flash='danger:Error: '.$e->getMessage(); }
}

$users = [];
try {
    $users = getDB()->query('SELECT id,username,full_name,role,created_at FROM users ORDER BY role ASC, username ASC')->fetchAll();
} catch (Exception $e) {}

echo sharedHead('Users');
echo sharedCSS();
?>
<style>
.users-layout { display:grid; grid-template-columns:1fr 340px; gap:24px; align-items:start; }
.user-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:18px 22px; box-shadow:var(--shadow); margin-bottom:14px; }
.user-card-top { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
.user-avatar { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:700; color:#fff; flex-shrink:0; }
.av-owner  { background:#1a56db; }
.av-helper { background:#6d28d9; }
.user-display-name { font-size:1rem; font-weight:700; color:var(--ink); }
.user-display-sub  { font-size:.88rem; color:var(--ink-3); margin-top:2px; }
.you-tag { font-size:.72rem; font-weight:700; background:var(--blue-bg); color:#1e429f; border:1px solid var(--blue-bdr); border-radius:4px; padding:2px 8px; margin-left:8px; vertical-align:middle; }
.user-actions { display:flex; flex-wrap:wrap; gap:10px; border-top:1px solid var(--border); padding-top:14px; align-items:center; }
.inline-form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.inline-form input, .inline-form select { padding:8px 12px; border:1px solid var(--border-dk); border-radius:var(--radius); font-family:var(--font); font-size:.9rem; color:var(--ink); background:var(--surface); }
.inline-form input:focus, .inline-form select:focus { outline:none; border-color:var(--blue); }
.add-panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:24px; box-shadow:var(--shadow); position:sticky; top:84px; }
.add-panel-title { font-size:.95rem; font-weight:700; color:var(--ink); margin-bottom:18px; padding-bottom:12px; border-bottom:1px solid var(--border); text-transform:uppercase; letter-spacing:.06em; }
.perm-row { display:flex; align-items:flex-start; gap:10px; padding:10px 0; border-bottom:1px solid var(--bg); font-size:.9rem; }
.perm-row:last-child { border-bottom:none; }
.perm-dot { width:11px; height:11px; border-radius:50%; flex-shrink:0; margin-top:4px; }
@media(max-width:820px){ .users-layout{ grid-template-columns:1fr; } .add-panel{ position:static; } }
</style>
</head><body>
<?= sharedNav('users') ?>
<div class="page">

<?php if ($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div class="flash flash-<?= $ft ?>"><?= htmlspecialchars($fm) ?></div>
<?php endif; ?>

<div class="sec-head">
  <span class="sec-title">User Accounts</span>
  <span class="sec-meta"><?= count($users) ?> account<?= count($users)!==1?'s':'' ?></span>
</div>

<div class="users-layout">

<!-- User list -->
<div>
<?php if (empty($users)): ?>
<div class="card"><div class="empty-state"><div class="e-label">No user accounts found</div><p>Run setup_users.php to create the default accounts.</p></div></div>
<?php endif; ?>

<?php foreach ($users as $u):
  $isMe     = ((int)$u['id'] === (int)$me['id']);
  $initials = strtoupper(substr($u['full_name'] ?: $u['username'], 0, 1));
  $avCls    = $u['role']==='owner' ? 'av-owner' : 'av-helper';
?>
<div class="user-card">
  <div class="user-card-top">
    <div class="user-avatar <?= $avCls ?>"><?= htmlspecialchars($initials) ?></div>
    <div>
      <div class="user-display-name">
        <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
        <?php if ($isMe): ?><span class="you-tag">You</span><?php endif; ?>
      </div>
      <div class="user-display-sub">
        @<?= htmlspecialchars($u['username']) ?>
        &nbsp;&middot;&nbsp;
        <span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span>
        &nbsp;&middot;&nbsp; Joined <?= date('d M Y', strtotime($u['created_at'])) ?>
      </div>
    </div>
  </div>
  <div class="user-actions">

    <!-- Change password -->
    <form method="POST" class="inline-form">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="uid" value="<?= $u['id'] ?>">
      <input type="password" name="new_password" placeholder="New password (min 6 chars)" minlength="6" style="width:230px">
      <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('Change password for <?= htmlspecialchars($u['username']) ?>?')">Change Password</button>
    </form>

    <?php if (!$isMe): ?>
    <!-- Change role -->
    <form method="POST" class="inline-form">
      <input type="hidden" name="action" value="change_role">
      <input type="hidden" name="uid" value="<?= $u['id'] ?>">
      <select name="role">
        <option value="owner"  <?= $u['role']==='owner' ?'selected':'' ?>>Owner</option>
        <option value="helper" <?= $u['role']==='helper'?'selected':'' ?>>Helper</option>
      </select>
      <button type="submit" class="btn btn-outline btn-sm">Change Role</button>
    </form>
    <!-- Delete -->
    <form method="POST" class="inline-form" onsubmit="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>? This cannot be undone.')">
      <input type="hidden" name="action" value="delete_user">
      <input type="hidden" name="uid" value="<?= $u['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">Delete User</button>
    </form>
    <?php else: ?>
    <span style="font-size:.88rem;color:var(--ink-4)">Role and account deletion are locked for your own account.</span>
    <?php endif; ?>

  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Add user panel -->
<div>
<div class="add-panel">
  <div class="add-panel-title">Add New User</div>
  <form method="POST">
    <input type="hidden" name="action" value="add_user">
    <div class="field" style="margin-bottom:14px">
      <label>Full Name</label>
      <input type="text" name="full_name" placeholder="e.g. Kasun Perera" maxlength="100">
    </div>
    <div class="field" style="margin-bottom:14px">
      <label>Username</label>
      <input type="text" name="username" placeholder="e.g. kasun123" maxlength="60" required autocomplete="off">
      <span class="field-hint">Must be unique. No spaces allowed.</span>
    </div>
    <div class="field" style="margin-bottom:14px">
      <label>Password</label>
      <input type="password" name="password" placeholder="Minimum 6 characters" minlength="6" required autocomplete="new-password">
    </div>
    <div class="field" style="margin-bottom:22px">
      <label>Role</label>
      <select name="role">
        <option value="helper" selected>Helper — View only</option>
        <option value="owner">Owner — Full access</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px">Create Account</button>
  </form>

  <div style="margin-top:22px;padding-top:16px;border-top:1px solid var(--border)">
    <div style="font-size:.85rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">Role Permissions</div>
    <div class="perm-row">
      <div class="perm-dot" style="background:#1a56db"></div>
      <div><strong>Owner</strong> — Can view everything and also add readings, download data, send reports, manage records, and manage user accounts.</div>
    </div>
    <div class="perm-row">
      <div class="perm-dot" style="background:#6d28d9"></div>
      <div><strong>Helper</strong> — Can view the live status page and sensor records only. Cannot make any changes.</div>
    </div>
  </div>
</div>
</div>

</div>
</div></body></html>
