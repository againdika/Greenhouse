<?php
// ══════════════════════════════════════════════════════════════════════
//  includes.php  —  Shared CSS, navigation, status helpers
// ══════════════════════════════════════════════════════════════════════

function sharedHead(string $title, bool $useChart = false): string {
    $chart = $useChart
        ? '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>'
        : '';
    return '<!DOCTYPE html><html lang="en"><head>'
         . '<meta charset="UTF-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
         . '<title>' . htmlspecialchars($title) . ' — Greenhouse Monitor</title>'
         . '<link rel="preconnect" href="https://fonts.googleapis.com">'
         . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">'
         . $chart
         . '<style>';
}

function sharedCSS(): string {
    return '
:root {
  --bg:        #f0f2f5;
  --surface:   #ffffff;
  --border:    #d4d8e1;
  --border-dk: #b0b7c3;
  --ink:       #0f1923;
  --ink-2:     #2d3748;
  --ink-3:     #5a6478;
  --ink-4:     #8a94a6;
  --green:     #155e2e;
  --green-bg:  #d1fae5;
  --green-bdr: #6ee7b7;
  --green-txt: #064e1e;
  --amber:     #854d0e;
  --amber-bg:  #fef3c7;
  --amber-bdr: #fbbf24;
  --amber-txt: #713f12;
  --red:       #7f1d1d;
  --red-bg:    #fee2e2;
  --red-bdr:   #f87171;
  --red-txt:   #6b1414;
  --blue:      #1a56db;
  --blue-bg:   #dbeafe;
  --blue-bdr:  #93c5fd;
  --navy:      #0c1a36;
  --radius:    8px;
  --radius-lg: 12px;
  --shadow:    0 1px 4px rgba(0,0,0,.07), 0 2px 8px rgba(0,0,0,.04);
  --shadow-md: 0 4px 20px rgba(0,0,0,.10);
  --font:      "Inter", sans-serif;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; -webkit-font-smoothing: antialiased; }
body { font-family: var(--font); background: var(--bg); color: var(--ink); line-height: 1.6; min-height: 100vh; }
a { color: var(--blue); text-decoration: none; }
a:hover { text-decoration: underline; }
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border-dk); border-radius: 3px; }

/* TOP BAR */
.topbar {
  background: var(--navy);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 36px; height: 68px;
  position: sticky; top: 0; z-index: 300; gap: 24px;
  box-shadow: 0 2px 10px rgba(0,0,0,.30);
}
.topbar-brand { display: flex; flex-direction: column; flex-shrink: 0; }
.topbar-title { font-size: 1.05rem; font-weight: 700; color: #fff; letter-spacing: .01em; }
.topbar-sub   { font-size: .78rem; color: #7e96b8; margin-top: 2px; }
.topbar-nav   { display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center; flex-wrap: wrap; }
.nav-link {
  padding: 9px 20px; border-radius: var(--radius);
  font-size: .95rem; font-weight: 500;
  text-decoration: none; color: #a8bcd4;
  transition: background .15s, color .15s;
  border: 1px solid transparent; white-space: nowrap;
}
.nav-link:hover { background: rgba(255,255,255,.10); color: #fff; text-decoration: none; }
.nav-link.active { background: var(--blue); color: #fff; border-color: #2563eb; }
.topbar-right { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
.live-dot   { width: 9px; height: 9px; border-radius: 50%; background: #4ade80; display: inline-block; margin-right: 6px; }
.live-label { font-size: .82rem; color: #7e96b8; display: flex; align-items: center; }
.user-chip  {
  display: flex; align-items: center; gap: 10px;
  background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
  border-radius: 28px; padding: 6px 16px 6px 8px;
  font-size: .88rem; color: #c4d5ea; cursor: default;
}
.user-initial {
  width: 30px; height: 30px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .82rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.init-owner  { background: #1a56db; }
.init-helper { background: #6d28d9; }
.user-name { font-weight: 600; font-size: .88rem; }
.user-role { font-size: .74rem; color: #7e96b8; }
.btn-signout {
  font-size: .88rem; font-weight: 500; color: #8da8c8;
  background: transparent; border: 1px solid rgba(255,255,255,.18);
  border-radius: var(--radius); padding: 7px 16px;
  cursor: pointer; text-decoration: none; transition: all .15s;
}
.btn-signout:hover { background: rgba(255,255,255,.08); color: #d0e2f0; text-decoration: none; }

/* PAGE */
.page    { max-width: 1280px; margin: 0 auto; padding: 36px 32px 72px; }
.page-sm { max-width:  900px; margin: 0 auto; padding: 36px 32px 72px; }

/* SECTION HEADER */
.sec-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 14px; border-bottom: 2px solid var(--border); }
.sec-title { font-size: 1.15rem; font-weight: 700; color: var(--ink); }
.sec-meta  { font-size: .9rem; color: var(--ink-3); font-weight: 500; }

/* CARD */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 28px 32px; box-shadow: var(--shadow); }

/* SENSOR GRID */
.sensor-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-bottom: 32px; }
.sensor-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius-lg); padding: 28px 32px;
  box-shadow: var(--shadow); border-top: 5px solid var(--border);
}
.sensor-card.s-ok     { border-top-color: var(--green); }
.sensor-card.s-warn   { border-top-color: #d97706; }
.sensor-card.s-danger { border-top-color: var(--red); }
.sc-label { font-size: .88rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-3); margin-bottom: 16px; }
.sc-value { font-size: 3.8rem; font-weight: 700; line-height: 1; color: var(--ink); margin-bottom: 6px; }
.sc-unit  { font-size: .9rem; color: var(--ink-4); margin-bottom: 18px; }
.status-pill { display: inline-block; font-size: .82rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; padding: 5px 16px; border-radius: 6px; border: 1px solid; }
.pill-ok     { color: var(--green-txt); border-color: var(--green-bdr); background: var(--green-bg); }
.pill-warn   { color: var(--amber-txt); border-color: var(--amber-bdr); background: var(--amber-bg); }
.pill-danger { color: var(--red-txt);   border-color: var(--red-bdr);   background: var(--red-bg); }
@media(max-width:700px){ .sensor-grid{ grid-template-columns:1fr; } }

/* ALERTS */
.alerts { display: flex; flex-direction: column; gap: 12px; margin-bottom: 30px; }
.alert {
  padding: 18px 24px; border-radius: var(--radius);
  font-size: 1.02rem; border: 1px solid; border-left: 5px solid;
  display: flex; align-items: flex-start; gap: 18px; line-height: 1.65;
}
.alert-ok     { background: var(--green-bg); border-color: var(--green-bdr); color: var(--green-txt); border-left-color: var(--green); }
.alert-warn   { background: var(--amber-bg); border-color: var(--amber-bdr); color: var(--amber-txt); border-left-color: #d97706; }
.alert-danger { background: var(--red-bg);   border-color: var(--red-bdr);   color: var(--red-txt);   border-left-color: var(--red); }
.alert-tag { font-size: .8rem; font-weight: 700; letter-spacing: .1em; opacity: .9; white-space: nowrap; padding-top: 3px; min-width: 120px; text-transform: uppercase; }

/* CHARTS */
.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
.chart-card  { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 22px 26px; box-shadow: var(--shadow); }
.chart-card.full { grid-column: 1/-1; }
.chart-title { font-size: .9rem; font-weight: 700; color: var(--ink-3); margin-bottom: 16px; text-transform: uppercase; letter-spacing: .06em; }
.chart-wrap  { position: relative; height: 170px; }
.chart-wrap.short { height: 130px; }
@media(max-width:700px){ .charts-grid{ grid-template-columns:1fr; } }

/* STATS */
.stat-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-bottom: 30px; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 22px 26px; box-shadow: var(--shadow); }
.stat-head { font-size: .88rem; font-weight: 700; color: var(--ink-2); margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border); text-transform: uppercase; letter-spacing: .06em; }
.stat-row  { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--bg); font-size: .95rem; }
.stat-row:last-child { border-bottom: none; }
.stat-key { color: var(--ink-3); font-weight: 500; }
.stat-val { color: var(--ink-2); font-weight: 600; }
.stat-val-warn   { color: #b45309 !important; font-weight: 700; }
.stat-val-danger { color: var(--red)   !important; font-weight: 700; }
@media(max-width:700px){ .stat-grid{ grid-template-columns:1fr; } }

/* TABLE */
.tbl-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow); }
table { width: 100%; border-collapse: collapse; font-size: .95rem; background: var(--surface); }
thead th { background: #f7f8fa; color: var(--ink-3); padding: 14px 18px; text-align: left; font-size: .82rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; border-bottom: 2px solid var(--border); }
tbody td  { padding: 13px 18px; border-bottom: 1px solid var(--border); color: var(--ink-2); font-size: .95rem; white-space: nowrap; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: #f7f8fa; }
.td-ok     { color: var(--green-txt) !important; font-weight: 700; }
.td-warn   { color: var(--amber-txt) !important; font-weight: 700; }
.td-danger { color: var(--red-txt)   !important; font-weight: 700; }
.td-id     { color: var(--ink-4)     !important; }

/* BADGES */
.badge { display: inline-block; font-size: .76rem; font-weight: 700; letter-spacing: .07em; padding: 4px 11px; border-radius: 5px; border: 1px solid; text-transform: uppercase; }
.badge-ok      { color: var(--green-txt); border-color: var(--green-bdr); background: var(--green-bg); }
.badge-warn    { color: var(--amber-txt); border-color: var(--amber-bdr); background: var(--amber-bg); }
.badge-danger  { color: var(--red-txt);   border-color: var(--red-bdr);   background: var(--red-bg); }
.badge-esp32   { color: #1e429f; border-color: #93c5fd; background: var(--blue-bg); }
.badge-manual  { color: #5b21b6; border-color: #c4b5fd; background: #ede9fe; }
.badge-owner   { color: #1e429f; border-color: #93c5fd; background: var(--blue-bg); }
.badge-helper  { color: #5b21b6; border-color: #c4b5fd; background: #ede9fe; }
.badge-online  { color: var(--green-txt); border-color: var(--green-bdr); background: var(--green-bg); }
.badge-offline { color: var(--red-txt);   border-color: var(--red-bdr);   background: var(--red-bg); }

/* BUTTONS */
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 24px; border-radius: var(--radius); font-family: var(--font); font-size: .95rem; font-weight: 600; text-decoration: none; border: 1px solid; cursor: pointer; transition: all .15s; white-space: nowrap; }
.btn:hover { text-decoration: none; }
.btn-primary { background: var(--blue); color: #fff; border-color: var(--blue); }
.btn-primary:hover { background: #1741b0; border-color: #1741b0; color: #fff; }
.btn-outline { background: transparent; color: var(--ink-2); border-color: var(--border-dk); }
.btn-outline:hover { background: #f3f4f6; color: var(--ink); }
.btn-danger { background: var(--red-bg); color: var(--red-txt); border-color: var(--red-bdr); }
.btn-danger:hover { background: #fecaca; border-color: var(--red); color: var(--red); }
.btn-warn   { background: var(--amber-bg); color: var(--amber-txt); border-color: var(--amber-bdr); }
.btn-warn:hover { background: #fde68a; border-color: #d97706; }
.btn-green  { background: var(--green-bg); color: var(--green-txt); border-color: var(--green-bdr); }
.btn-green:hover { background: #a7f3d0; border-color: var(--green); }
.btn-sm { padding: 8px 18px; font-size: .88rem; }
.btn-group { display: flex; gap: 10px; flex-wrap: wrap; }

/* FORMS */
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px,1fr)); gap: 20px; }
.field { display: flex; flex-direction: column; gap: 7px; }
.field label { font-size: .92rem; font-weight: 600; color: var(--ink-2); }
.field input, .field select, .field textarea { background: var(--surface); border: 1px solid var(--border-dk); border-radius: var(--radius); color: var(--ink); padding: 12px 16px; font-family: var(--font); font-size: 1rem; transition: border .15s; width: 100%; }
.field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(26,86,219,.12); }
.field input.err { border-color: var(--red); }
.field-hint    { font-size: .84rem; color: var(--ink-4); }
.field-err-msg { font-size: .84rem; color: var(--red-txt); font-weight: 600; }

/* FLASH */
.flash { padding: 16px 22px; border-radius: var(--radius); font-size: 1rem; border: 1px solid; border-left: 5px solid; margin-bottom: 24px; line-height: 1.6; font-weight: 500; }
.flash-ok     { background: var(--green-bg); border-color: var(--green-bdr); color: var(--green-txt); border-left-color: var(--green); }
.flash-warn   { background: var(--amber-bg); border-color: var(--amber-bdr); color: var(--amber-txt); border-left-color: #d97706; }
.flash-danger { background: var(--red-bg);   border-color: var(--red-bdr);   color: var(--red-txt);   border-left-color: var(--red); }

/* FILTER BAR */
.filter-bar { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 18px 24px; margin-bottom: 22px; display: flex; align-items: flex-end; flex-wrap: wrap; gap: 18px; box-shadow: var(--shadow); }
.filter-bar label { font-size: .9rem; font-weight: 600; color: var(--ink-2); display: block; margin-bottom: 6px; }
.filter-bar select, .filter-bar input[type=date] { background: var(--surface); border: 1px solid var(--border-dk); border-radius: var(--radius); color: var(--ink); padding: 10px 14px; font-family: var(--font); font-size: .95rem; }
.filter-bar select:focus, .filter-bar input:focus { outline: none; border-color: var(--blue); }

/* PAGINATION */
.pagination { display: flex; align-items: center; justify-content: space-between; padding: 20px 0; flex-wrap: wrap; gap: 12px; }
.page-links { display: flex; gap: 5px; flex-wrap: wrap; }
.page-link  { display: inline-block; padding: 9px 16px; border-radius: var(--radius); font-size: .92rem; font-weight: 500; text-decoration: none; color: var(--ink-2); border: 1px solid var(--border); background: var(--surface); transition: all .15s; }
.page-link:hover { background: #f3f4f6; color: var(--ink); text-decoration: none; }
.page-link.current  { background: var(--blue); color: #fff; border-color: var(--blue); }
.page-link.disabled { opacity: .35; pointer-events: none; }
.page-info { font-size: .9rem; color: var(--ink-3); font-weight: 500; }

/* KV LIST */
.kv-list { display: grid; grid-template-columns: max-content 1fr; gap: 10px 36px; }
.kv-key  { font-size: .95rem; color: var(--ink-3); font-weight: 500; white-space: nowrap; }
.kv-val  { font-size: .95rem; color: var(--ink-2); font-weight: 600; }

/* MISC */
.db-error { background: var(--red-bg); border: 1px solid var(--red-bdr); border-left: 5px solid var(--red); color: var(--red-txt); border-radius: var(--radius); padding: 18px 22px; margin-bottom: 24px; font-size: 1rem; line-height: 1.6; }
.empty-state { text-align: center; padding: 72px 24px; color: var(--ink-4); }
.empty-state .e-label { font-size: 1.4rem; font-weight: 700; margin-bottom: 12px; color: var(--ink-3); }
.empty-state p { font-size: 1rem; }
.divider { border: none; border-top: 1px solid var(--border); margin: 24px 0; }
.text-ok     { color: var(--green-txt); }
.text-warn   { color: var(--amber-txt); }
.text-danger { color: var(--red-txt); }
.readonly-notice { background: #ede9fe; border: 1px solid #c4b5fd; border-left: 5px solid #7c3aed; border-radius: var(--radius); padding: 14px 20px; font-size: .95rem; color: #4c1d95; margin-bottom: 22px; font-weight: 500; }
';
}

function sharedNav(string $active): string {
    $role    = $_SESSION['user_role']     ?? 'helper';
    $name    = $_SESSION['user_name']     ?? ($_SESSION['user_username'] ?? '');
    $uname   = $_SESSION['user_username'] ?? '';
    $isOwner = ($role === 'owner');

    $nav  = '<div class="topbar">';
    $nav .= '<div class="topbar-brand">'
          . '<div class="topbar-title">Greenhouse Monitor</div>'
          . '<div class="topbar-sub">Chile Cultivation &mdash; Galle, Sri Lanka &mdash; ' . date('H:i') . ' SLT</div>'
          . '</div>';
    $nav .= '<nav class="topbar-nav">';
    $nav .= navLink('dashboard.php',   'Live Status',    'dashboard', $active);
    $nav .= navLink('log.php',         'Sensor Records', 'log',       $active);
    if ($isOwner) {
        $nav .= navLink('devices.php',       'Devices',       'devices', $active);
        $nav .= navLink('manual.php',        'Add Reading',   'manual',  $active);
        $nav .= navLink('export.php',        'Download Data', 'export',  $active);
        $nav .= navLink('weekly_report.php', 'Weekly Report', 'report',  $active);
        $nav .= navLink('admin.php',         'Manage Data',   'admin',   $active);
        $nav .= navLink('users.php',         'Users',         'users',   $active);
    }
    $nav .= '</nav>';

    $initials = strtoupper(substr($name ?: $uname, 0, 1));
    $initCls  = $isOwner ? 'init-owner' : 'init-helper';
    $roleLbl  = $isOwner ? 'Owner' : 'Helper';

    $nav .= '<div class="topbar-right">'
          . '<span class="live-label"><span class="live-dot"></span>Live</span>'
          . '<div class="user-chip">'
          . '<div class="user-initial ' . $initCls . '">' . htmlspecialchars($initials) . '</div>'
          . '<div><div class="user-name">' . htmlspecialchars($name ?: $uname) . '</div>'
          . '<div class="user-role">' . $roleLbl . '</div></div>'
          . '</div>'
          . '<a href="logout.php" class="btn-signout">Sign Out</a>'
          . '</div></div>';
    return $nav;
}

function navLink(string $href, string $label, string $key, string $active): string {
    $cls = 'nav-link' . ($key === $active ? ' active' : '');
    return '<a class="' . $cls . '" href="' . $href . '">' . $label . '</a>';
}

function tempStatus(float $t): string  { if($t<15||$t>35)return'DANGER'; if($t<18||$t>32)return'WARNING'; return'NORMAL'; }
function humStatus(float $h): string   { if($h<40||$h>90)return'DANGER'; if($h<50||$h>85)return'WARNING'; return'NORMAL'; }
function gasStatus(int $g): string     { if($g>1500)return'DANGER'; if($g>800)return'WARNING'; return'NORMAL'; }
function stClass(string $s): string    { return match($s){'DANGER'=>'s-danger','WARNING'=>'s-warn',default=>'s-ok'}; }
function tdClass(string $s): string    { return match($s){'DANGER'=>'td-danger','WARNING'=>'td-warn',default=>'td-ok'}; }
function pillClass(string $s): string  { return match($s){'DANGER'=>'pill-danger','WARNING'=>'pill-warn',default=>'pill-ok'}; }
function badgeClass(string $s): string { return match($s){'DANGER'=>'badge-danger','WARNING'=>'badge-warn',default=>'badge-ok'}; }
