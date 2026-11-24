<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'admin_helpers.php';

function admin_request_int(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $id === false ? null : (int) $id;
}

function admin_error(string $message, int $status = 422): never
{
    chat_json_response(['status' => 'error', 'message' => $message], $status);
}

function admin_update_user(PDO $pdo, int $adminId, array $data): never
{
    $userId = admin_request_int($data['user_id'] ?? null);
    $status = (string) ($data['status'] ?? '');
    $role = (string) ($data['role'] ?? '');

    if ($userId === null) {
        admin_error('Choose a valid user.');
    }
    if ($userId === $adminId) {
        admin_error('You cannot change your own administrator access here.');
    }

    $userStatement = $pdo->prepare('SELECT id, role, status FROM users WHERE id = ? LIMIT 1');
    $userStatement->execute([$userId]);
    $target = $userStatement->fetch();
    if (!$target) {
        admin_error('User not found.', 404);
    }

    $fields = [];
    $values = [];
    if ($status !== '') {
        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            admin_error('Invalid account status.');
        }
        $fields[] = 'status = ?';
        $values[] = $status;
    }
    if ($role !== '') {
        if (!in_array($role, ['user', 'admin'], true)) {
            admin_error('Invalid user role.');
        }
        if ($role === 'user' && (string) $target['role'] === 'admin') {
            $admins = admin_scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
            if ($admins <= 1) {
                admin_error('Keep at least one active administrator.');
            }
        }
        $fields[] = 'role = ?';
        $values[] = $role;
    }

    if ($fields === []) {
        admin_error('No user change was requested.');
    }

    $values[] = $userId;
    $statement = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $statement->execute($values);

    chat_json_response(['status' => 'success', 'message' => 'User updated successfully.']);
}

function admin_update_report(PDO $pdo, int $adminId, array $data): never
{
    $reportId = admin_request_int($data['report_id'] ?? null);
    $status = (string) ($data['status'] ?? '');

    if ($reportId === null || !in_array($status, ['pending', 'resolved', 'dismissed'], true)) {
        admin_error('Choose a valid report and status.');
    }

    $statement = $pdo->prepare(
        'UPDATE reports SET status = ?, reviewed_by = ?, reviewed_at = CASE WHEN ? = \'pending\' THEN NULL ELSE CURRENT_TIMESTAMP END WHERE id = ?'
    );
    $statement->execute([$status, $status === 'pending' ? null : $adminId, $status, $reportId]);

    chat_json_response(['status' => 'success', 'message' => 'Report status updated.']);
}

function admin_delete_message(PDO $pdo, array $data): never
{
    $messageId = admin_request_int($data['message_id'] ?? null);
    if ($messageId === null) {
        admin_error('Choose a valid message.');
    }

    $statement = $pdo->prepare('DELETE FROM messages WHERE id = ?');
    $statement->execute([$messageId]);

    chat_json_response(['status' => 'success', 'message' => 'Message removed.']);
}

try {
    $pdo = chat_db();
    $adminId = chat_require_admin($pdo);
    $action = (string) ($_GET['action'] ?? '');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($action === 'overview' && $method === 'GET') {
        chat_json_response(['status' => 'success', 'data' => admin_overview($pdo)]);
    }
    if ($action === 'users' && $method === 'GET') {
        $search = mb_substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
        $status = (string) ($_GET['status'] ?? 'all');
        $role = (string) ($_GET['role'] ?? 'all');
        chat_json_response(['status' => 'success', 'data' => admin_users($pdo, $search, $status, $role)]);
    }
    if ($action === 'reports' && $method === 'GET') {
        chat_json_response(['status' => 'success', 'data' => admin_reports($pdo, (string) ($_GET['status'] ?? 'pending'))]);
    }
    if ($action === 'messages' && $method === 'GET') {
        chat_json_response(['status' => 'success', 'data' => admin_recent_messages($pdo)]);
    }
    if ($action === 'system' && $method === 'GET') {
        chat_json_response(['status' => 'success', 'data' => admin_system_status($pdo)]);
    }

    if ($method !== 'POST') {
        admin_error('Endpoint not found.', 404);
    }

    chat_require_csrf();
    $data = chat_request_data();

    if ($action === 'update-user') {
        admin_update_user($pdo, $adminId, $data);
    }
    if ($action === 'update-report') {
        admin_update_report($pdo, $adminId, $data);
    }
    if ($action === 'delete-message') {
        admin_delete_message($pdo, $data);
    }

    admin_error('Endpoint not found.', 404);
} catch (Throwable $exception) {
    error_log('[php-chat-app admin] ' . $exception->getMessage());
    $message = chat_config()['app']['debug'] ? $exception->getMessage() : 'The administrator request failed.';
    chat_json_response(['status' => 'error', 'message' => $message], 500);
}
