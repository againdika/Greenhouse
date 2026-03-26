<?php
// ══════════════════════════════════════════════════════════════════════
// owner / owner123    helper / helper123  ::: ONE TIME RUN :::
// ══════════════════════════════════════════════════════════════════════
require_once dirname(__FILE__) . '/db.php';

$results = []; $ok = false;
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(60)  NOT NULL UNIQUE,
        password   VARCHAR(255) NOT NULL,
        role       ENUM('owner','helper') NOT NULL DEFAULT 'helper',
        full_name  VARCHAR(100) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $accounts = [
        ['owner',  'owner123',  'owner',  'Farm Owner'],
        ['helper', 'helper123', 'helper', 'Farm Helper'],
    ];
    foreach ($accounts as [$uname, $plain, $role, $name]) {
        $hash = password_hash($plain, PASSWORD_BCRYPT);
        $stmt = $db->prepare('INSERT INTO users (username, password, role, full_name) VALUES (:u,:p,:r,:n) ON DUPLICATE KEY UPDATE password=:p, role=:r, full_name=:n');
        $stmt->execute([':u'=>$uname,':p'=>$hash,':r'=>$role,':n'=>$name]);
        $results[] = 'User "' . $uname . '" (' . $role . ') created successfully.';
    }
    $ok = true;
} catch (Exception $e) {
    $results[] = 'Error: ' . $e->getMessage();
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Setup Users — Greenhouse Monitor</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:"Inter",sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border:1px solid #d4d8e1;border-radius:12px;padding:36px 44px;max-width:500px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.10)}
h2{margin:0 0 22px;font-size:1.2rem;color:#0f1923}
.msg{padding:12px 16px;border-radius:8px;margin-bottom:10px;font-size:.95rem;border:1px solid}
.ok{background:#d1fae5;border-color:#6ee7b7;color:#064e1e}
.err{background:#fee2e2;border-color:#f87171;color:#6b1414}
.warn{background:#fef3c7;border-color:#fbbf24;color:#713f12;margin-top:20px;padding:16px;font-size:.92rem;line-height:1.7}
a{color:#1a56db}
</style>
</head>
<body>
<div class="box">
  <h2>Greenhouse — User Setup</h2>
  <?php foreach ($results as $r): ?>
  <div class="msg <?= $ok?'ok':'err' ?>"><?= htmlspecialchars($r) ?></div>
  <?php endforeach; ?>
  <?php if ($ok): ?>
  <div class="msg warn">
    <strong>Important:</strong> Delete this file (setup_users.php) from your server immediately.<br><br>
    Default credentials:<br>
    Username: <strong>owner</strong> &nbsp; Password: <strong>owner123</strong><br>
    Username: <strong>helper</strong> &nbsp; Password: <strong>helper123</strong><br><br>
    Please change both passwords after your first login via the Users page.
  </div>
  <p style="margin-top:18px;font-size:.95rem"><a href="login.php">Go to Login</a></p>
  <?php endif; ?>
</div>
</body>
</html>
