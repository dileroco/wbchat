<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function chat_validation_error(string $message, array $errors = []): never
{
    chat_json_response([
        'status' => 'error',
        'message' => $message,
        'errors' => $errors,
    ], 422);
}

function chat_text(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function chat_get_user(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT id, full_name, email, username, password, profile_picture, role, status, last_seen_at, created_at FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);
    $user = $statement->fetch();

    return $user ?: null;
}

function chat_login(array $data, PDO $pdo): never
{
    $username = strtolower(chat_text($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($username === '' || $password === '') {
        chat_validation_error('Username and password are required.', [
            'username' => ['Username is required.'],
            'password' => ['Password is required.'],
        ]);
    }

    if (mb_strlen($username) > 50) {
        chat_validation_error('Please check the form and try again.', ['username' => ['Username is too long.']]);
    }

    $statement = $pdo->prepare('SELECT id, full_name, email, username, password, profile_picture, role, status, last_seen_at, created_at FROM users WHERE username = ? LIMIT 1');
    $statement->execute([$username]);
    $user = $statement->fetch();

    if (!$user || !password_verify($password, (string) $user['password'])) {
        chat_json_response(['status' => 'error', 'message' => 'Incorrect username or password.'], 401);
    }

    if (($user['status'] ?? 'active') !== 'active') {
        chat_json_response(['status' => 'error', 'message' => 'This account is not active.'], 403);
    }

    if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
        $rehash = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['loggedin'] = true;

    chat_json_response([
        'status' => 'success',
        'message' => 'Login successful.',
        'data' => chat_user_data($user),
    ]);
}

function chat_signup(array $data, PDO $pdo): never
{
    $fullName = chat_text($data['full_name'] ?? '');
    $email = strtolower(chat_text($data['email'] ?? ''));
    $username = strtolower(chat_text($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $confirmation = (string) ($data['password_confirmation'] ?? '');
    $errors = [];

    if ($fullName === '' || mb_strlen($fullName) > 255) {
        $errors['full_name'] = ['Full name is required and must be 255 characters or fewer.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $errors['email'] = ['Please enter a valid email address.'];
    }
    if ($username === '' || mb_strlen($username) > 50) {
        $errors['username'] = ['Username is required and must be 50 characters or fewer.'];
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = ['Password must be at least 8 characters.'];
    }
    if ($password !== $confirmation) {
        $errors['password_confirmation'] = ['Passwords do not match.'];
    }

    if ($errors !== []) {
        chat_validation_error('Please check the form and try again.', $errors);
    }

    $duplicate = $pdo->prepare('SELECT email, username FROM users WHERE email = ? OR username = ? LIMIT 1');
    $duplicate->execute([$email, $username]);
    $existing = $duplicate->fetch();

    if ($existing) {
        $errors = [];
        if (strcasecmp((string) $existing['email'], $email) === 0) {
            $errors['email'] = ['That email is already registered.'];
        }
        if (strcasecmp((string) $existing['username'], $username) === 0) {
            $errors['username'] = ['That username is already taken.'];
        }
        chat_validation_error('Please choose different account details.', $errors);
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (full_name, email, username, password, profile_picture, created_at) VALUES (?, ?, ?, ?, NULL, CURRENT_TIMESTAMP)'
    );
    $statement->execute([$fullName, $email, $username, password_hash($password, PASSWORD_DEFAULT)]);

    $user = chat_get_user($pdo, (int) $pdo->lastInsertId());
    if (!$user) {
        throw new RuntimeException('The new account could not be loaded.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['loggedin'] = true;

    chat_json_response([
        'status' => 'success',
        'message' => 'Signup successful.',
        'data' => chat_user_data($user),
    ], 201);
}

function chat_list_contacts(PDO $pdo, int $userId, string $search, bool $allUsers): never
{
    $pattern = '%' . $search . '%';

    if ($allUsers) {
        $statement = $pdo->prepare(<<<'SQL'
SELECT
    u.id AS user_id,
    u.full_name,
    u.username,
    COALESCE(u.profile_picture, '') AS profile_picture,
    (
        SELECT MAX(m.created_at)
        FROM messages m
        WHERE (m.sender_id = ? AND m.receiver_id = u.id)
           OR (m.sender_id = u.id AND m.receiver_id = ?)
    ) AS last_message_time,
    (
        SELECT COUNT(*)
        FROM messages unread_messages
        WHERE unread_messages.sender_id = u.id
          AND unread_messages.receiver_id = ?
          AND unread_messages.status = 'unread'
    ) AS unread_count
FROM users u
WHERE u.id != ?
  AND u.status = 'active'
  AND (LOWER(u.full_name) LIKE LOWER(?) OR LOWER(u.username) LIKE LOWER(?))
ORDER BY last_message_time DESC, u.id ASC
SQL);
        $statement->execute([$userId, $userId, $userId, $userId, $pattern, $pattern]);
    } else {
        $statement = $pdo->prepare(<<<'SQL'
SELECT
    u.id AS user_id,
    u.full_name,
    u.username,
    COALESCE(u.profile_picture, '') AS profile_picture,
    (
        SELECT COUNT(*)
        FROM messages unread_messages
        WHERE unread_messages.sender_id = u.id
          AND unread_messages.receiver_id = ?
          AND unread_messages.status = 'unread'
    ) AS unread_count,
    (
        SELECT MAX(m.created_at)
        FROM messages m
        WHERE (m.sender_id = u.id AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = u.id)
    ) AS last_message_time
FROM users u
WHERE u.id IN (
    SELECT messages.sender_id FROM messages WHERE messages.receiver_id = ?
    UNION
    SELECT messages.receiver_id FROM messages WHERE messages.sender_id = ?
)
AND u.status = 'active'
AND (LOWER(u.full_name) LIKE LOWER(?) OR LOWER(u.username) LIKE LOWER(?))
ORDER BY last_message_time DESC, u.id ASC
SQL);
        $statement->execute([$userId, $userId, $userId, $userId, $userId, $pattern, $pattern]);
    }

    $contacts = array_map(static function (array $contact): array {
        $contact['user_id'] = (int) $contact['user_id'];
        $contact['unread_count'] = (int) ($contact['unread_count'] ?? 0);
        $contact['profile_picture'] = (string) ($contact['profile_picture'] ?? '');

        return $contact;
    }, $statement->fetchAll());

    chat_json_response([
        'status' => 'success',
        'message' => $allUsers ? 'Users retrieved successfully.' : 'Contacts retrieved successfully.',
        'data' => $contacts,
    ]);
}

function chat_fetch_messages(PDO $pdo, int $senderId, array $data): never
{
    $receiverId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    $receiver = $receiverId ? chat_get_user($pdo, (int) $receiverId) : null;
    if (!$receiver || ($receiver['status'] ?? 'active') !== 'active') {
        chat_validation_error('Please choose a valid contact.', ['id' => ['The selected contact does not exist.']]);
    }

    $markRead = $pdo->prepare(
        "UPDATE messages SET status = 'read' WHERE sender_id = ? AND receiver_id = ? AND status = 'unread'"
    );
    $markRead->execute([(int) $receiverId, $senderId]);

    $statement = $pdo->prepare(<<<'SQL'
SELECT id, sender_id, receiver_id, content, status, created_at
FROM messages
WHERE (sender_id = ? AND receiver_id = ?)
   OR (sender_id = ? AND receiver_id = ?)
ORDER BY created_at ASC, id ASC
SQL);
    $statement->execute([$senderId, $receiverId, $receiverId, $senderId]);
    $messages = array_map('chat_normalize_message', $statement->fetchAll());

    chat_json_response([
        'status' => 'success',
        'message' => 'Messages fetched successfully.',
        'data' => $messages,
    ]);
}

function chat_send_message(PDO $pdo, int $senderId, array $data): never
{
    $receiverId = filter_var($data['receiver_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $content = chat_text($data['content'] ?? '');

    $receiver = $receiverId ? chat_get_user($pdo, (int) $receiverId) : null;
    if (!$receiver || ($receiver['status'] ?? 'active') !== 'active') {
        chat_validation_error('Please choose a valid contact.', ['receiver_id' => ['The selected contact does not exist.']]);
    }
    if ((int) $receiverId === $senderId) {
        chat_validation_error('You cannot send a message to yourself.');
    }
    if ($content === '' || mb_strlen($content) > 5000) {
        chat_validation_error('Message content is required and must be 5000 characters or fewer.', ['content' => ['Enter a message before sending.']]);
    }

    $message = chat_create_message($pdo, $senderId, (int) $receiverId, $content);

    chat_json_response([
        'status' => 'success',
        'message' => 'Message sent successfully.',
        'data' => $message,
    ], 201);
}

function chat_report_message(PDO $pdo, int $reporterId, array $data): never
{
    $messageId = filter_var($data['message_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $reason = chat_text($data['reason'] ?? 'Inappropriate content');

    if (!$messageId) {
        chat_validation_error('Please choose a valid message.', ['message_id' => ['The message does not exist.']]);
    }
    if ($reason === '' || mb_strlen($reason) > 255) {
        chat_validation_error('Please provide a short reason for the report.', ['reason' => ['The reason is required.']]);
    }

    $messageStatement = $pdo->prepare(
        'SELECT id, sender_id, receiver_id FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?) LIMIT 1'
    );
    $messageStatement->execute([(int) $messageId, $reporterId, $reporterId]);
    $message = $messageStatement->fetch();

    if (!$message) {
        chat_json_response(['status' => 'error', 'message' => 'You can only report messages from your conversations.'], 403);
    }

    $duplicate = $pdo->prepare(
        "SELECT id FROM reports WHERE reporter_id = ? AND message_id = ? AND status = 'pending' LIMIT 1"
    );
    $duplicate->execute([$reporterId, (int) $messageId]);
    if ($duplicate->fetchColumn()) {
        chat_json_response(['status' => 'success', 'message' => 'This message is already reported.']);
    }

    $reportedUserId = (int) $message['sender_id'] === $reporterId
        ? (int) $message['receiver_id']
        : (int) $message['sender_id'];
    $statement = $pdo->prepare(
        'INSERT INTO reports (reporter_id, message_id, reported_user_id, reason, status, created_at) VALUES (?, ?, ?, ?, \'pending\', CURRENT_TIMESTAMP)'
    );
    $statement->execute([$reporterId, (int) $messageId, $reportedUserId, $reason]);

    chat_json_response(['status' => 'success', 'message' => 'Report submitted for review.'], 201);
}

function chat_profile(PDO $pdo, int $userId): never
{
    $user = chat_get_user($pdo, $userId);

    if (!$user) {
        session_destroy();
        chat_json_response(['status' => 'error', 'message' => 'User not found.'], 404);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        chat_json_response([
            'status' => 'success',
            'message' => 'User data fetched successfully.',
            'data' => chat_user_data($user),
        ]);
    }

    $data = chat_request_data();
    $fullName = chat_text($data['full_name'] ?? '');
    $password = (string) ($data['password'] ?? '');

    if ($fullName === '' || mb_strlen($fullName) > 255) {
        chat_validation_error('Full name is required and must be 255 characters or fewer.', ['full_name' => ['Full name is required.']]);
    }
    if ($password !== '' && mb_strlen($password) < 8) {
        chat_validation_error('Password must be at least 8 characters.', ['password' => ['Password must be at least 8 characters.']]);
    }

    $profilePicture = (string) ($user['profile_picture'] ?? '');
    $file = $_FILES['profile_picture'] ?? null;

    if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            chat_validation_error('The profile picture could not be uploaded.');
        }
        if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            chat_validation_error('Profile pictures must be 2 MB or smaller.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file((string) $file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        if (!isset($extensions[$mime])) {
            chat_validation_error('Upload a JPG, PNG, GIF, or WebP image.');
        }

        $newName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $destination = chat_config()['uploads_path'] . DIRECTORY_SEPARATOR . $newName;

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            chat_validation_error('The profile picture could not be saved.');
        }

        if ($profilePicture !== '') {
            $oldPath = chat_config()['uploads_path'] . DIRECTORY_SEPARATOR . basename($profilePicture);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $profilePicture = $newName;
    }

    $fields = ['full_name = ?', 'profile_picture = ?'];
    $values = [$fullName, $profilePicture];

    if ($password !== '') {
        $fields[] = 'password = ?';
        $values[] = password_hash($password, PASSWORD_DEFAULT);
    }

    $values[] = $userId;
    $statement = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $statement->execute($values);

    $updated = chat_get_user($pdo, $userId);
    $_SESSION['user_id'] = $userId;

    chat_json_response([
        'status' => 'success',
        'message' => 'Profile updated successfully.',
        'data' => chat_user_data($updated ?: $user),
    ]);
}

try {
    $action = (string) ($_GET['action'] ?? '');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $pdo = chat_db();

    if ($action === 'login' && $method === 'POST') {
        chat_require_csrf();
        chat_login(chat_request_data(), $pdo);
    }
    if ($action === 'signup' && $method === 'POST') {
        chat_require_csrf();
        chat_signup(chat_request_data(), $pdo);
    }
    if ($action === 'logout' && $method === 'POST') {
        chat_require_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        chat_json_response(['status' => 'success', 'message' => 'User successfully logged out.']);
    }

    $userId = chat_require_login();

    if ($action === 'profile' && in_array($method, ['GET', 'POST'], true)) {
        if ($method === 'POST') {
            chat_require_csrf();
        }
        chat_profile($pdo, $userId);
    }
    if ($action === 'users' && $method === 'GET') {
        $search = mb_substr(chat_text($_GET['search'] ?? ''), 0, 100);
        chat_list_contacts($pdo, $userId, $search, true);
    }
    if ($action === 'contacts' && $method === 'GET') {
        $search = mb_substr(chat_text($_GET['search'] ?? ''), 0, 100);
        chat_list_contacts($pdo, $userId, $search, false);
    }
    if ($action === 'messages' && $method === 'POST') {
        chat_require_csrf();
        chat_fetch_messages($pdo, $userId, chat_request_data());
    }
    if ($action === 'send-message' && $method === 'POST') {
        chat_require_csrf();
        chat_send_message($pdo, $userId, chat_request_data());
    }
    if ($action === 'report-message' && $method === 'POST') {
        chat_require_csrf();
        chat_report_message($pdo, $userId, chat_request_data());
    }

    chat_json_response(['status' => 'error', 'message' => 'Endpoint not found.'], 404);
} catch (Throwable $exception) {
    error_log('[php-chat-app] ' . $exception->getMessage());
    $message = chat_config()['app']['debug'] ? $exception->getMessage() : 'Something went wrong. Please try again.';
    chat_json_response(['status' => 'error', 'message' => $message], 500);
}
