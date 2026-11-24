<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this migration from the command line.\n");
}

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

$sqlitePath = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'chat.sqlite';

if (!is_file($sqlitePath)) {
    throw new RuntimeException('The SQLite database was not found: ' . $sqlitePath);
}

$sqlite = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$mysql = chat_db();
$tables = ['users', 'messages', 'reports'];
$sourceCounts = [];

foreach ($tables as $table) {
    $sourceCounts[$table] = (int) $sqlite->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    $targetCount = (int) $mysql->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();

    if ($targetCount !== 0) {
        throw new RuntimeException(sprintf(
            'Refusing to migrate into a non-empty MySQL table: %s contains %d rows.',
            $table,
            $targetCount
        ));
    }
}

$mysql->beginTransaction();

try {
    $insertUser = $mysql->prepare(
        'INSERT INTO users (id, full_name, email, username, password, profile_picture, role, status, last_seen_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($sqlite->query('SELECT id, full_name, email, username, password, profile_picture, role, status, last_seen_at, created_at FROM users ORDER BY id') as $user) {
        $insertUser->execute([
            (int) $user['id'],
            $user['full_name'],
            $user['email'],
            $user['username'],
            $user['password'],
            $user['profile_picture'],
            $user['role'],
            $user['status'],
            $user['last_seen_at'],
            $user['created_at'],
        ]);
    }

    $insertMessage = $mysql->prepare(
        'INSERT INTO messages (id, sender_id, receiver_id, content, status, created_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($sqlite->query('SELECT id, sender_id, receiver_id, content, status, created_at FROM messages ORDER BY id') as $message) {
        $insertMessage->execute([
            (int) $message['id'],
            (int) $message['sender_id'],
            (int) $message['receiver_id'],
            $message['content'],
            $message['status'],
            $message['created_at'],
        ]);
    }

    $insertReport = $mysql->prepare(
        'INSERT INTO reports (id, reporter_id, message_id, reported_user_id, reason, status, reviewed_by, reviewed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($sqlite->query('SELECT id, reporter_id, message_id, reported_user_id, reason, status, reviewed_by, reviewed_at, created_at FROM reports ORDER BY id') as $report) {
        $insertReport->execute([
            (int) $report['id'],
            (int) $report['reporter_id'],
            $report['message_id'] !== null ? (int) $report['message_id'] : null,
            $report['reported_user_id'] !== null ? (int) $report['reported_user_id'] : null,
            $report['reason'],
            $report['status'],
            $report['reviewed_by'] !== null ? (int) $report['reviewed_by'] : null,
            $report['reviewed_at'],
            $report['created_at'],
        ]);
    }

    $mysql->commit();
} catch (Throwable $exception) {
    if ($mysql->inTransaction()) {
        $mysql->rollBack();
    }

    throw $exception;
}

foreach ($tables as $table) {
    $actual = (int) $mysql->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    if ($actual !== $sourceCounts[$table]) {
        throw new RuntimeException(sprintf(
            'Migration verification failed for %s: expected %d rows, found %d.',
            $table,
            $sourceCounts[$table],
            $actual
        ));
    }
}

fwrite(STDOUT, sprintf(
    "Migrated SQLite to MySQL successfully: users=%d, messages=%d, reports=%d.\n",
    $sourceCounts['users'],
    $sourceCounts['messages'],
    $sourceCounts['reports']
));
