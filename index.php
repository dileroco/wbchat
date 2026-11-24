<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

$sessionUserId = chat_session_user_id();
$currentUser = null;

if ($sessionUserId !== null) {
    try {
        $currentUser = chat_current_user(chat_db(), $sessionUserId);
    } catch (Throwable $exception) {
        error_log('[php-chat-app] ' . $exception->getMessage());
    }

    if ($currentUser === null) {
        $_SESSION = [];
        session_regenerate_id(true);
        $sessionUserId = null;
    }
}

$page = (string) ($_GET['page'] ?? 'login');
$isRegister = $page === 'register';
$csrfToken = chat_csrf_token();
$config = chat_config();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
$socketHost = (string) ($_SERVER['SERVER_NAME'] ?? '127.0.0.1');
$websocketUrl = sprintf('%s://%s:%d', $scheme, $socketHost, $config['websocket']['port']);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= chat_escape($csrfToken) ?>">
    <title><?= chat_escape($config['app']['name']) ?></title>
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.15.4/css/all.css">
    <link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body class="<?= $currentUser ? 'chat-page' : 'auth-page' ?>" data-page="<?= $currentUser ? 'chat' : ($isRegister ? 'register' : 'login') ?>" data-user-id="<?= (int) ($currentUser['id'] ?? 0) ?>" data-api-url="api.php" data-websocket-url="<?= chat_escape($websocketUrl) ?>" data-websocket-enabled="<?= $config['websocket']['enabled'] ? 'true' : 'false' ?>" data-websocket-port="<?= (int) $config['websocket']['port'] ?>">
<?php if (!$currentUser): ?>
    <main class="auth-shell">
        <form class="auth-form" id="auth-form" method="post" action="api.php?action=<?= $isRegister ? 'signup' : 'login' ?>" novalidate>
            <span class="auth-title"><?= $isRegister ? 'Create account' : 'Welcome back' ?></span>
            <span class="auth-subtitle"><?= $isRegister ? 'Join and start chatting :)' : 'Sign in to chat now :)' ?></span>
            <p class="form-error" id="form-error" role="alert" hidden></p>

            <?php if ($isRegister): ?>
                <input class="auth-input" type="text" name="full_name" placeholder="Full name" autocomplete="name" required maxlength="255">
                <input class="auth-input" type="email" name="email" placeholder="Email" autocomplete="email" required maxlength="255">
            <?php endif; ?>
            <input class="auth-input" type="text" name="username" placeholder="Username" autocomplete="username" required maxlength="50">
            <input class="auth-input" type="password" name="password" placeholder="Password" autocomplete="<?= $isRegister ? 'new-password' : 'current-password' ?>" required minlength="8">
            <?php if ($isRegister): ?>
                <input class="auth-input" type="password" name="password_confirmation" placeholder="Confirm password" autocomplete="new-password" required minlength="8">
            <?php endif; ?>

            <?php if ($isRegister): ?>
                <span class="auth-switch">Already have an account? <a href="index.php?page=login">Sign in</a></span>
                <button class="auth-button" type="submit">Register</button>
            <?php else: ?>
                <span class="auth-switch">Need an account? <a href="index.php?page=register">Register</a></span>
                <button class="auth-button" type="submit">Sign in</button>
            <?php endif; ?>
        </form>
    </main>
<?php else: ?>
    <main class="frame">
        <button class="logout-menu" id="logout-button" type="button" title="Log out" aria-label="Log out"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></button>
        <?php if (($currentUser['role'] ?? 'user') === 'admin'): ?>
            <a class="admin-link" href="admin.php">Admin</a>
        <?php endif; ?>

        <aside class="sidebar" aria-label="Contacts">
            <div class="sidebar-top">
                <button class="profile-trigger" id="profile-trigger" type="button" aria-label="Open your profile">
                    <img id="current-avatar" src="<?= chat_escape(chat_profile_picture_url($currentUser['profile_picture'] ?? null)) ?>" alt="Your profile">
                </button>
                <div class="search">
                    <label class="sr-only" for="contact-search">Search contacts</label>
                    <input id="contact-search" type="search" placeholder="Search..." autocomplete="off">
                </div>
            </div>

            <div class="contacts" id="contacts" aria-live="polite">
                <p class="no-contacts">Loading contacts...</p>
            </div>

            <button class="fab-add" id="toggle-users" type="button" title="Find users" aria-label="Find users">+</button>
        </aside>

        <section class="chat empty-chat" id="chat-panel" aria-live="polite">
            <div class="empty-chat-content" id="empty-chat">
                <p>Select a contact to start chatting</p>
            </div>
        </section>
    </main>

    <div class="profile-overlay" id="profile-overlay" hidden>
        <div class="profile-modal" role="dialog" aria-modal="true" aria-labelledby="profile-title">
            <button class="profile-close" id="profile-close" type="button" aria-label="Close profile">×</button>
            <h2 id="profile-title">Profile</h2>
            <p class="profile-message" id="profile-message" hidden></p>
            <img class="profile-preview" id="profile-preview" src="<?= chat_escape(chat_profile_picture_url($currentUser['profile_picture'] ?? null)) ?>" alt="Profile">

            <form class="profile-form" id="profile-form" enctype="multipart/form-data">
                <label class="sr-only" for="profile-name">Full name</label>
                <input id="profile-name" type="text" name="full_name" value="<?= chat_escape($currentUser['full_name'] ?? '') ?>" placeholder="Full name" required maxlength="255">
                <label class="sr-only" for="profile-password">New password</label>
                <input id="profile-password" type="password" name="password" placeholder="New password (optional)" minlength="8" autocomplete="new-password">
                <input id="profile-picture" type="file" name="profile_picture" accept="image/png,image/jpeg,image/gif,image/webp">
                <button type="submit">Save</button>
            </form>
        </div>
    </div>
<?php endif; ?>
<script src="assets/app.js?v=5" defer></script>
</body>
</html>
