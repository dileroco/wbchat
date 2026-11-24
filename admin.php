<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'admin_helpers.php';

$userId = chat_session_user_id();
if ($userId === null) {
    header('Location: index.php?page=login');
    exit;
}

$pdo = chat_db();
$currentUser = chat_current_user($pdo, $userId);
if (!$currentUser || !chat_is_admin($pdo, $userId)) {
    http_response_code(403);
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><title>Access denied</title><link rel="stylesheet" href="assets/app.css?v=2"></head>
    <body class="auth-page"><main class="auth-shell"><div class="auth-form"><span class="auth-title">Access denied</span><span class="auth-subtitle">Administrator access is required.</span><a class="auth-button" href="index.php">Back to chat</a></div></main></body></html><?php
    exit;
}

$config = chat_config();
$csrfToken = chat_csrf_token();
$overview = admin_overview($pdo);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= chat_escape($csrfToken) ?>">
    <title>Admin dashboard · <?= chat_escape($config['app']['name']) ?></title>
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.15.4/css/all.css">
    <link rel="stylesheet" href="assets/admin.css?v=1">
</head>
<body class="admin-body" data-api-url="admin-api.php" data-chat-url="index.php" data-user-id="<?= (int) $userId ?>">
    <header class="admin-topbar">
        <div class="admin-brand">
            <span class="brand-mark"><i class="fas fa-shield-alt" aria-hidden="true"></i></span>
            <span><strong>PHP Chat</strong><small>Administration</small></span>
        </div>
        <div class="admin-account">
            <span><strong><?= chat_escape($currentUser['full_name']) ?></strong><small>@<?= chat_escape($currentUser['username']) ?></small></span>
            <button id="admin-logout" type="button" title="Log out" aria-label="Log out"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></button>
        </div>
    </header>

    <div class="admin-layout">
        <aside class="admin-sidebar">
            <p class="sidebar-label">Workspace</p>
            <button class="admin-nav active" type="button" data-tab="overview"><i class="fas fa-chart-pie" aria-hidden="true"></i> Overview</button>
            <button class="admin-nav" type="button" data-tab="users"><i class="fas fa-users" aria-hidden="true"></i> User management</button>
            <button class="admin-nav" type="button" data-tab="moderation"><i class="fas fa-gavel" aria-hidden="true"></i> Moderation <span class="nav-badge" id="nav-report-count">0</span></button>
            <button class="admin-nav" type="button" data-tab="system"><i class="fas fa-heartbeat" aria-hidden="true"></i> System monitoring</button>
            <div class="sidebar-bottom"><a href="index.php"><i class="fas fa-comments" aria-hidden="true"></i> Back to chat</a></div>
        </aside>

        <main class="admin-content">
            <section class="admin-tab active" id="tab-overview" data-tab-panel="overview">
                <div class="page-heading"><div><p class="eyebrow">Control center</p><h1>Overview</h1><p>Keep an eye on your community and messaging activity.</p></div><button class="outline-button refresh-button" data-refresh="overview" type="button"><i class="fas fa-sync-alt" aria-hidden="true"></i> Refresh</button></div>
                <div class="stat-grid">
                    <article class="stat-card accent-blue"><span class="stat-icon"><i class="fas fa-users" aria-hidden="true"></i></span><div><small>Total users</small><strong id="stat-total-users"><?= (int) $overview['total_users'] ?></strong><span>Registered accounts</span></div></article>
                    <article class="stat-card accent-green"><span class="stat-icon"><i class="fas fa-user-check" aria-hidden="true"></i></span><div><small>Online now</small><strong id="stat-online-users"><?= (int) $overview['online_users'] ?></strong><span><b id="stat-active-users"><?= (int) $overview['active_users'] ?></b> active accounts</span></div></article>
                    <article class="stat-card accent-purple"><span class="stat-icon"><i class="fas fa-comments" aria-hidden="true"></i></span><div><small>Total messages</small><strong id="stat-total-messages"><?= (int) $overview['total_messages'] ?></strong><span><b id="stat-messages-day"><?= (int) $overview['messages_last_24h'] ?></b> in the last 24h</span></div></article>
                    <article class="stat-card accent-orange"><span class="stat-icon"><i class="fas fa-flag" aria-hidden="true"></i></span><div><small>Pending reports</small><strong id="stat-pending-reports"><?= (int) $overview['pending_reports'] ?></strong><span>Needs review</span></div></article>
                </div>
                <div class="dashboard-grid">
                    <article class="panel"><div class="panel-heading"><div><h2>Account status</h2><p>Current user status distribution.</p></div></div><div class="status-bars" id="status-bars"></div></article>
                    <article class="panel"><div class="panel-heading"><div><h2>Quick health</h2><p>Live application checks.</p></div><button class="text-button" data-tab-link="system" type="button">View system</button></div><div class="health-list" id="overview-health"><div class="loading-row">Checking system health…</div></div></article>
                </div>
            </section>

            <section class="admin-tab" id="tab-users" data-tab-panel="users">
                <div class="page-heading"><div><p class="eyebrow">Community</p><h1>User management</h1><p>Search accounts, change access, and control user status.</p></div><button class="outline-button refresh-button" data-refresh="users" type="button"><i class="fas fa-sync-alt" aria-hidden="true"></i> Refresh</button></div>
                <article class="panel table-panel"><div class="toolbar"><label class="search-control"><i class="fas fa-search" aria-hidden="true"></i><input id="user-search" type="search" placeholder="Search name, username, or email…"></label><select id="user-status"><option value="all">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="banned">Banned</option></select><select id="user-role"><option value="all">All roles</option><option value="admin">Admins</option><option value="user">Users</option></select></div><div class="table-scroll"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last seen</th><th>Joined</th><th class="align-right">Actions</th></tr></thead><tbody id="users-table"><tr><td colspan="6" class="loading-row">Loading users…</td></tr></tbody></table></div></article>
            </section>

            <section class="admin-tab" id="tab-moderation" data-tab-panel="moderation">
                <div class="page-heading"><div><p class="eyebrow">Trust &amp; safety</p><h1>Moderation</h1><p>Review user reports and remove harmful messages.</p></div><button class="outline-button refresh-button" data-refresh="moderation" type="button"><i class="fas fa-sync-alt" aria-hidden="true"></i> Refresh</button></div>
                <div class="moderation-grid"><article class="panel table-panel"><div class="panel-heading"><div><h2>Reports queue</h2><p>Reports waiting for a decision.</p></div><select id="report-status"><option value="pending">Pending</option><option value="resolved">Resolved</option><option value="dismissed">Dismissed</option><option value="all">All reports</option></select></div><div class="table-scroll"><table><thead><tr><th>Report</th><th>Message</th><th>Created</th><th class="align-right">Actions</th></tr></thead><tbody id="reports-table"><tr><td colspan="4" class="loading-row">Loading reports…</td></tr></tbody></table></div></article><article class="panel table-panel"><div class="panel-heading"><div><h2>Recent messages</h2><p>Moderate content directly.</p></div></div><div class="table-scroll"><table><thead><tr><th>Conversation</th><th>Message</th><th>Time</th><th class="align-right">Actions</th></tr></thead><tbody id="messages-table"><tr><td colspan="4" class="loading-row">Loading messages…</td></tr></tbody></table></div></article></div>
            </section>

            <section class="admin-tab" id="tab-system" data-tab-panel="system">
                <div class="page-heading"><div><p class="eyebrow">Operations</p><h1>System monitoring</h1><p>Check the services this chat depends on.</p></div><button class="outline-button refresh-button" data-refresh="system" type="button"><i class="fas fa-sync-alt" aria-hidden="true"></i> Refresh</button></div>
                <div class="system-grid" id="system-grid"><div class="loading-row">Checking system health…</div></div>
            </section>
            <p class="admin-toast" id="admin-toast" role="status" hidden></p>
        </main>
    </div>
    <script src="assets/admin.js?v=1" defer></script>
</body>
</html>
