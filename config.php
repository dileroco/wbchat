<?php

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

/**
 * Read the small .env file used by this standalone PHP app. A full dotenv
 * dependency is deliberately not required so the project works in XAMPP.
 */
function chat_env_file(): array
{
    static $values;

    if ($values !== null) {
        return $values;
    }

    $values = [];
    $path = APP_ROOT . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($path)) {
        return $values;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) {
            $value = trim($value, "\"'");
        }

        $values[$key] = $value;
    }

    return $values;
}

function chat_env(string $key, mixed $default = null): mixed
{
    $fileValues = chat_env_file();

    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    $serverValue = $_SERVER[$key] ?? null;
    if ($serverValue !== null && $serverValue !== '') {
        return $serverValue;
    }

    $systemValue = getenv($key);
    if ($systemValue !== false && $systemValue !== '') {
        return $systemValue;
    }

    return $fileValues[$key] ?? $default;
}

$dbDriver = strtolower((string) chat_env('CHAT_DB_CONNECTION', chat_env('DB_CONNECTION', 'sqlite')));
$dbDatabase = (string) chat_env(
    'CHAT_DB_DATABASE',
    chat_env('DB_DATABASE', APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'chat.sqlite')
);

if ($dbDriver === 'sqlite' && $dbDatabase !== ':memory:' && !preg_match('/^[A-Za-z]:[\\\\\/]/', $dbDatabase)) {
    $dbDatabase = APP_ROOT . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dbDatabase), DIRECTORY_SEPARATOR);
}

return [
    'app' => [
        'name' => (string) chat_env('CHAT_APP_NAME', 'PHP WebSocket Chat'),
        'debug' => filter_var(chat_env('CHAT_DEBUG', false), FILTER_VALIDATE_BOOL),
    ],
    'database' => [
        'driver' => $dbDriver,
        'database' => $dbDatabase,
        'host' => (string) chat_env('CHAT_DB_HOST', chat_env('DB_HOST', '127.0.0.1')),
        'port' => (int) chat_env('CHAT_DB_PORT', chat_env('DB_PORT', 3306)),
        'username' => (string) chat_env('CHAT_DB_USERNAME', chat_env('DB_USERNAME', 'root')),
        'password' => (string) chat_env('CHAT_DB_PASSWORD', chat_env('DB_PASSWORD', '')),
        'charset' => (string) chat_env('CHAT_DB_CHARSET', 'utf8mb4'),
    ],
    'session' => [
        'name' => (string) chat_env('CHAT_SESSION_NAME', 'phpchat_session'),
        'lifetime' => 120,
    ],
    'websocket' => [
        'enabled' => filter_var(chat_env('CHAT_WS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'host' => (string) chat_env('CHAT_WS_HOST', '0.0.0.0'),
        'port' => (int) chat_env('CHAT_WS_PORT', 8080),
    ],
    'uploads_path' => APP_ROOT . DIRECTORY_SEPARATOR . 'uploads',
];
