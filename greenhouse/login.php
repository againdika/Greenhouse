<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

require_once dirname(__FILE__) . '/db.php';

$error = '';
$next  = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $_GET['next'] ?? 'dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password to continue.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['user_username'] = $user['username'];
                $_SESSION['user_name']     = $user['full_name'] ?: $user['username'];
                $_SESSION['user_role']     = $user['role'];
                header('Location: ' . $next);
                exit;
            } else {
                $error = 'The username or password you entered is incorrect. Please try again.';
            }
        } catch (Exception $e) {
            $error = 'Unable to connect to the database. Please try again shortly.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In — Greenhouse Monitor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f2f5; --surface:#fff; --border:#d4d8e1; --border-dk:#b0b7c3;
  --ink:#0f1923; --ink-2:#2d3748; --ink-3:#5a6478; --ink-4:#8a94a6;
  --blue:#1a56db; --navy:#0c1a36;
  --red:#7f1d1d; --red-bg:#fee2e2; --red-bdr:#f87171; --red-txt:#6b1414;
  --green:#155e2e; --green-bg:#d1fae5; --green-bdr:#6ee7b7;
  --radius:8px; --radius-lg:12px;
  --shadow-md:0 4px 24px rgba(0,0,0,.13);
  --font:"Inter",sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;-webkit-font-smoothing:antialiased}
body{font-family:var(--font);background:var(--bg);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px}

.page-wrap { width:100%; max-width:460px; }

/* Brand block */
.brand { text-align:center; margin-bottom:36px; }
.brand-logo {
  width:60px; height:60px; background:var(--navy); border-radius:14px;
  margin:0 auto 16px; display:flex; align-items:center; justify-content:center;
}
.brand-logo-bar {
  width:26px; height:3px; background:#fff; border-radius:2px;
  box-shadow: 0 8px 0 #fff, 0 16px 0 #4a80d4;
}
.brand-name { font-size:1.4rem; font-weight:700; color:var(--ink); }
.brand-sub  { font-size:.95rem; color:var(--ink-3); margin-top:5px; }

/* Card */
.login-card {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius-lg); padding:40px 44px;
  box-shadow:var(--shadow-md);
}
.card-title { font-size:1.5rem; font-weight:700; color:var(--ink); margin-bottom:6px; }
.card-sub   { font-size:1rem; color:var(--ink-3); margin-bottom:32px; }

/* Role buttons */
.role-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:32px; }
.role-btn {
  padding:14px 0; text-align:center; font-size:1rem; font-weight:600;
  cursor:pointer; border-radius:var(--radius); background:var(--bg);
  color:var(--ink-3); border:2px solid var(--border); font-family:var(--font);
  transition:all .15s; letter-spacing:.01em;
}
.role-btn:hover { border-color:var(--border-dk); color:var(--ink-2); background:#eaecf0; }
.role-btn.active { background:var(--navy); color:#fff; border-color:var(--navy); }

/* Fields */
.field { display:flex; flex-direction:column; gap:7px; margin-bottom:20px; }
.field label { font-size:.95rem; font-weight:600; color:var(--ink-2); }
.field input {
  background:var(--bg); border:1.5px solid var(--border-dk); border-radius:var(--radius);
  color:var(--ink); padding:14px 18px; font-family:var(--font); font-size:1.05rem;
  transition:border .15s; width:100%;
}
.field input:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(26,86,219,.12); background:var(--surface); }
.field input.err { border-color:var(--red); }

/* Error */
.error-box {
  background:var(--red-bg); border:1px solid var(--red-bdr);
  border-left:4px solid var(--red); border-radius:var(--radius);
  padding:14px 18px; font-size:.97rem; color:var(--red-txt);
  margin-bottom:22px; line-height:1.6; font-weight:500;
}

/* Submit */
.btn-login {
  width:100%; padding:15px; background:var(--blue); color:#fff;
  border:none; border-radius:var(--radius); font-family:var(--font);
  font-size:1.05rem; font-weight:700; cursor:pointer;
  transition:background .15s; margin-top:6px; letter-spacing:.01em;
}
.btn-login:hover { background:#1741b0; }

/* Access info */
.access-info {
  background:var(--bg); border:1px solid var(--border); border-radius:var(--radius);
  padding:16px 20px; margin-top:24px;
  font-size:.92rem; color:var(--ink-3); line-height:1.8;
}
.access-info strong { color:var(--ink-2); }
.role-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:7px; vertical-align:middle; }
.rd-owner  { background:#1a56db; }
.rd-helper { background:#6d28d9; }

.page-footer { margin-top:28px; font-size:.82rem; color:var(--ink-4); text-align:center; }
</style>
</head>
<body>

<div class="page-wrap">
  <div class="brand">
    <div class="brand-logo"><div class="brand-logo-bar"></div></div>
    <div class="brand-name">Greenhouse Monitor</div>
    <div class="brand-sub">Chile Cultivation &mdash; Sri Lanka</div>
  </div>

  <div class="login-card">
    <div class="card-title">Welcome Back</div>
    <div class="card-sub">Please sign in to access your greenhouse.</div>

    <div class="role-row">
      <button type="button" class="role-btn active" onclick="setRole(this,'owner')">Farm Owner</button>
      <button type="button" class="role-btn" onclick="setRole(this,'helper')">Farm Helper</button>
    </div>

    <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php<?= $next !== 'dashboard.php' ? '?next='.urlencode($next) : '' ?>">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" name="username" id="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               autocomplete="username" autofocus
               class="<?= $error ? 'err' : '' ?>"
               placeholder="Enter your username">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" name="password" id="password"
               autocomplete="current-password"
               class="<?= $error ? 'err' : '' ?>"
               placeholder="Enter your password">
      </div>
      <button type="submit" class="btn-login">Sign In</button>
    </form>

    <div class="access-info">
      <span class="role-dot rd-owner"></span><strong>Owner</strong> — Full access to all features including data entry, reports and settings.<br>
      <span class="role-dot rd-helper"></span><strong>Helper</strong> — View the live status and sensor records only.
    </div>
  </div>

  <div class="page-footer">Greenhouse Monitor &nbsp;&middot;&nbsp; Sri Lanka &nbsp;&middot;&nbsp; <?= date('Y') ?></div>
</div>

<script>
function setRole(el, role) {
  document.querySelectorAll('.role-btn').forEach(function(b){ b.classList.remove('active'); });
  el.classList.add('active');
  var u = document.getElementById('username');
  if (u.value === '' || u.value === 'owner' || u.value === 'helper') {
    u.value = role === 'owner' ? 'owner' : 'helper';
    u.focus();
  }
}
</script>
</body>
</html>
