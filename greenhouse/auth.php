<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function requireOwner(): void {
    requireLogin();
    if ($_SESSION['user_role'] !== 'owner') {
        http_response_code(403);
        require_once dirname(__FILE__) . '/includes.php';
        echo sharedHead('Access Restricted');
        echo sharedCSS();
        echo '</style></head><body>';
        echo sharedNav($_SESSION['user_role'] ?? 'helper');
        echo '<div class="page-sm" style="text-align:center;padding-top:80px">';
        echo '<div style="font-size:1.4rem;font-weight:700;color:var(--ink-3);margin-bottom:12px">Owner Access Required</div>';
        echo '<p style="font-size:1rem;color:var(--ink-4);margin-bottom:28px">This section is restricted to the farm owner account. Please contact the farm owner if you need access.</p>';
        echo '<a href="dashboard.php" class="btn btn-outline">Back to Dashboard</a>';
        echo '</div></body></html>';
        exit;
    }
}

function currentUser(): array {
    return [
        'id'       => $_SESSION['user_id']       ?? 0,
        'username' => $_SESSION['user_username']  ?? '',
        'name'     => $_SESSION['user_name']      ?? '',
        'role'     => $_SESSION['user_role']      ?? 'helper',
    ];
}

function isOwner(): bool  { return ($_SESSION['user_role'] ?? '') === 'owner'; }
function isHelper(): bool { return ($_SESSION['user_role'] ?? '') === 'helper'; }
