<?php

declare(strict_types=1);

function admin_scalar(PDO $pdo, string $sql, array $parameters = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
}

function admin_overview(PDO $pdo): array
{
    $onlineCutoff = gmdate('Y-m-d H:i:s', time() - 300);
    $messageCutoff = gmdate('Y-m-d H:i:s', time() - 86400);

    return [
        'total_users' => admin_scalar($pdo, 'SELECT COUNT(*) FROM users'),
        'active_users' => admin_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'active'"),
        'suspended_users' => admin_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'suspended'"),
        'banned_users' => admin_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'banned'"),
        'online_users' => admin_scalar($pdo, 'SELECT COUNT(*) FROM users WHERE status = \'active\' AND last_seen_at >= ?', [$onlineCutoff]),
        'total_messages' => admin_scalar($pdo, 'SELECT COUNT(*) FROM messages'),
        'messages_last_24h' => admin_scalar($pdo, 'SELECT COUNT(*) FROM messages WHERE created_at >= ?', [$messageCutoff]),
        'pending_reports' => admin_scalar($pdo, "SELECT COUNT(*) FROM reports WHERE status = 'pending'"),
    ];
}

function admin_users(PDO $pdo, string $search = '', string $status = 'all', string $role = 'all'): array
{
    $conditions = [];
    $parameters = [];

    if ($search !== '') {
        $conditions[] = '(LOWER(full_name) LIKE LOWER(?) OR LOWER(username) LIKE LOWER(?) OR LOWER(email) LIKE LOWER(?))';
        $pattern = '%' . $search . '%';
        array_push($parameters, $pattern, $pattern, $pattern);
    }
    if (in_array($status, ['active', 'suspended', 'banned'], true)) {
        $conditions[] = 'status = ?';
        $parameters[] = $status;
    }
    if (in_array($role, ['user', 'admin'], true)) {
        $conditions[] = 'role = ?';
        $parameters[] = $role;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $statement = $pdo->prepare(
        "SELECT id, full_name, email, username, profile_picture, role, status, last_seen_at, created_at
         FROM users {$where} ORDER BY created_at DESC, id DESC LIMIT 200"
    );
    $statement->execute($parameters);

    return array_map(static function (array $user): array {
        $user['id'] = (int) $user['id'];
        $user['role'] = (string) ($user['role'] ?? 'user');
        $user['status'] = (string) ($user['status'] ?? 'active');

        return $user;
    }, $statement->fetchAll());
}

function admin_reports(PDO $pdo, string $status = 'pending'): array
{
    $where = in_array($status, ['pending', 'resolved', 'dismissed'], true) ? 'WHERE r.status = ?' : '';
    $parameters = $where ? [$status] : [];
    $statement = $pdo->prepare(
        "SELECT
            r.id,
            r.reason,
            r.status,
            r.created_at,
            r.reviewed_at,
            reporter.full_name AS reporter_name,
            reported.full_name AS reported_name,
            r.message_id,
            m.content AS message_content
         FROM reports r
         LEFT JOIN users reporter ON reporter.id = r.reporter_id
         LEFT JOIN users reported ON reported.id = r.reported_user_id
         LEFT JOIN messages m ON m.id = r.message_id
         {$where}
         ORDER BY r.created_at DESC, r.id DESC
         LIMIT 200"
    );
    $statement->execute($parameters);

    return array_map(static function (array $report): array {
        $report['id'] = (int) $report['id'];
        $report['message_id'] = $report['message_id'] !== null ? (int) $report['message_id'] : null;

        return $report;
    }, $statement->fetchAll());
}

function admin_recent_messages(PDO $pdo): array
{
    $statement = $pdo->query(<<<'SQL'
SELECT
    m.id,
    m.sender_id,
    m.receiver_id,
    m.content,
    m.status,
    m.created_at,
    sender.full_name AS sender_name,
    receiver.full_name AS receiver_name
FROM messages m
LEFT JOIN users sender ON sender.id = m.sender_id
LEFT JOIN users receiver ON receiver.id = m.receiver_id
ORDER BY m.created_at DESC, m.id DESC
LIMIT 200
SQL);

    return array_map(static function (array $message): array {
        $message['id'] = (int) $message['id'];
        $message['sender_id'] = (int) $message['sender_id'];
        $message['receiver_id'] = (int) $message['receiver_id'];

        return $message;
    }, $statement->fetchAll());
}

function admin_system_status(PDO $pdo): array
{
    $config = chat_config();
    $databaseOk = false;
    $databaseError = null;

    try {
        $pdo->query('SELECT 1')->fetchColumn();
        $databaseOk = true;
    } catch (Throwable $exception) {
        $databaseError = $exception->getMessage();
    }

    $socketReachable = false;
    $socketError = null;
    $socket = @fsockopen('127.0.0.1', (int) $config['websocket']['port'], $errorNumber, $errorMessage, 0.25);
    if (is_resource($socket)) {
        $socketReachable = true;
        fclose($socket);
    } else {
        $socketError = $errorMessage ?: 'WebSocket process is not listening.';
    }

    $freeSpace = @disk_free_space(APP_ROOT);

    return [
        'php_version' => PHP_VERSION,
        'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI),
        'database_driver' => (string) $config['database']['driver'],
        'database_name' => (string) $config['database']['database'],
        'database_ok' => $databaseOk,
        'database_error' => $databaseError,
        'websocket_port' => (int) $config['websocket']['port'],
        'websocket_ok' => $socketReachable,
        'websocket_error' => $socketError,
        'storage_writable' => is_writable(APP_ROOT . DIRECTORY_SEPARATOR . 'storage'),
        'uploads_writable' => is_writable($config['uploads_path']),
        'memory_usage' => memory_get_usage(true),
        'disk_free' => $freeSpace === false ? null : (int) $freeSpace,
        'checked_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    ];
}
