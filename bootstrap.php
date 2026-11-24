<?php

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

$chatConfig = require APP_ROOT . DIRECTORY_SEPARATOR . 'config.php';

date_default_timezone_set('UTC');

if ($chatConfig['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

foreach ([APP_ROOT . '/storage', $chatConfig['uploads_path']] as $directory) {
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
}

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name($chatConfig['session']['name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function chat_config(): array
{
    global $chatConfig;

    return $chatConfig;
}

function chat_db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = chat_config()['database'];
    $driver = strtolower((string) $config['driver']);

    if ($driver === 'sqlite') {
        $databasePath = (string) $config['database'];
        $parent = dirname($databasePath);

        if ($databasePath !== ':memory:' && !is_dir($parent)) {
            @mkdir($parent, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } else {
        throw new RuntimeException('Unsupported database driver: ' . $driver);
    }

    chat_migrate($pdo, $driver);

    return $pdo;
}

function chat_migrate(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    profile_picture VARCHAR(255) NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER NOT NULL,
    receiver_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'unread',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS messages_pair_created_at ON messages (sender_id, receiver_id, created_at)');
        chat_ensure_user_columns($pdo, $driver);
        chat_create_reports_table($pdo, $driver);
        return;
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    profile_picture VARCHAR(255) NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_id BIGINT UNSIGNED NOT NULL,
    receiver_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'unread',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX messages_pair_created_at (sender_id, receiver_id, created_at),
    CONSTRAINT messages_sender_fk FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT messages_receiver_fk FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    chat_ensure_user_columns($pdo, $driver);
    chat_create_reports_table($pdo, $driver);
}

function chat_column_exists(PDO $pdo, string $driver, string $table, string $column): bool
{
    if ($driver === 'sqlite') {
        $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($statement->fetchAll() as $definition) {
            if (($definition['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$table, $column]);

    return (int) $statement->fetchColumn() > 0;
}

function chat_ensure_user_columns(PDO $pdo, string $driver): void
{
    $definitions = $driver === 'sqlite'
        ? [
            'role' => "VARCHAR(20) NOT NULL DEFAULT 'user'",
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
            'last_seen_at' => 'DATETIME NULL',
        ]
        : [
            'role' => "VARCHAR(20) NOT NULL DEFAULT 'user'",
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
            'last_seen_at' => 'TIMESTAMP NULL',
        ];

    foreach ($definitions as $column => $definition) {
        if (!chat_column_exists($pdo, $driver, 'users', $column)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}

function chat_create_reports_table(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_id INTEGER NOT NULL,
    message_id INTEGER NULL,
    reported_user_id INTEGER NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    reviewed_by INTEGER NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS reports_status_created_at ON reports (status, created_at)');

        return;
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reporter_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    reported_user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX reports_status_created_at (status, created_at),
    CONSTRAINT reports_reporter_fk FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT reports_message_fk FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL,
    CONSTRAINT reports_reported_user_fk FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT reports_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
}

function chat_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function chat_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo chat_json($payload);
    exit;
}

function chat_request_data(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    return $_POST;
}

function chat_csrf_token(): string
{
    if (PHP_SAPI === 'cli') {
        return '';
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function chat_require_csrf(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $expected = chat_csrf_token();
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_token'] ?? ''));

    if ($expected === '' || !hash_equals($expected, $provided)) {
        chat_json_response(['status' => 'error', 'message' => 'The page token has expired. Refresh and try again.'], 419);
    }
}

function chat_session_user_id(): ?int
{
    $id = $_SESSION['user_id'] ?? null;

    return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
}

function chat_require_login(): int
{
    $userId = chat_session_user_id();

    if ($userId === null) {
        chat_json_response([
            'status' => 'error',
            'message' => 'User is not logged in',
            'data' => ['loggedin' => false],
        ], 401);
    }

    $user = chat_current_user(chat_db(), $userId);
    if (!$user) {
        $_SESSION = [];
        session_regenerate_id(true);
        chat_json_response(['status' => 'error', 'message' => 'User not found.'], 401);
    }

    if (($user['status'] ?? 'active') !== 'active') {
        chat_json_response(['status' => 'error', 'message' => 'This account is not active.'], 403);
    }

    chat_touch_user(chat_db(), $userId);

    return $userId;
}

function chat_normalize_user(array $user): array
{
    return [
        'id' => (int) ($user['id'] ?? $user['user_id'] ?? 0),
        'user_id' => (int) ($user['user_id'] ?? $user['id'] ?? 0),
        'full_name' => (string) ($user['full_name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'profile_picture' => (string) ($user['profile_picture'] ?? ''),
        'role' => (string) ($user['role'] ?? 'user'),
        'status' => (string) ($user['status'] ?? 'active'),
        'last_seen_at' => $user['last_seen_at'] ?? null,
        'created_at' => $user['created_at'] ?? null,
    ];
}

function chat_user_data(array $user): array
{
    $data = chat_normalize_user($user);
    $data['loggedin'] = true;

    return $data;
}

function chat_normalize_message(array $message): array
{
    return [
        'id' => (int) ($message['id'] ?? 0),
        'sender_id' => (int) ($message['sender_id'] ?? 0),
        'receiver_id' => (int) ($message['receiver_id'] ?? 0),
        'content' => (string) ($message['content'] ?? ''),
        'status' => (string) ($message['status'] ?? 'unread'),
        'created_at' => (string) ($message['created_at'] ?? ''),
    ];
}

function chat_current_user(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT id, full_name, email, username, profile_picture, role, status, last_seen_at, created_at FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);
    $user = $statement->fetch();

    return $user ?: null;
}

function chat_touch_user(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare('UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?');
    $statement->execute([$userId]);
}

function chat_is_admin(PDO $pdo, int $userId): bool
{
    $statement = $pdo->prepare("SELECT role FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $statement->execute([$userId]);

    return (string) $statement->fetchColumn() === 'admin';
}

function chat_require_admin(PDO $pdo): int
{
    $userId = chat_require_login();

    if (!chat_is_admin($pdo, $userId)) {
        chat_json_response(['status' => 'error', 'message' => 'Administrator access is required.'], 403);
    }

    return $userId;
}

function chat_message(PDO $pdo, int $messageId): ?array
{
    $statement = $pdo->prepare('SELECT id, sender_id, receiver_id, content, status, created_at FROM messages WHERE id = ? LIMIT 1');
    $statement->execute([$messageId]);
    $message = $statement->fetch();

    return $message ? chat_normalize_message($message) : null;
}

function chat_create_message(PDO $pdo, int $senderId, int $receiverId, string $content): array
{
    $statement = $pdo->prepare(
        "INSERT INTO messages (sender_id, receiver_id, content, status, created_at) VALUES (?, ?, ?, 'unread', CURRENT_TIMESTAMP)"
    );
    $statement->execute([$senderId, $receiverId, $content]);

    $message = chat_message($pdo, (int) $pdo->lastInsertId());

    if ($message === null) {
        throw new RuntimeException('The message could not be loaded after saving.');
    }

    return $message;
}

function chat_profile_picture_url(?string $filename): string
{
    $filename = basename((string) $filename);

    return $filename !== '' ? 'uploads/' . rawurlencode($filename) : 'assets/default-avatar.png';
}

function chat_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
