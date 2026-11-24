<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

$username = strtolower(trim((string) ($argv[1] ?? '')));
if ($username === '') {
    fwrite(STDERR, "Usage: php make-admin.php <username>\n");
    exit(1);
}

$pdo = chat_db();
$statement = $pdo->prepare('SELECT id, full_name, username FROM users WHERE username = ? LIMIT 1');
$statement->execute([$username]);
$user = $statement->fetch();

if (!$user) {
    fwrite(STDERR, "User not found. Register the user in the chat first, then run this command again.\n");
    exit(1);
}

$update = $pdo->prepare("UPDATE users SET role = 'admin', status = 'active' WHERE id = ?");
$update->execute([(int) $user['id']]);

fwrite(STDOUT, sprintf("Administrator access granted to %s (@%s).\n", $user['full_name'], $user['username']));
