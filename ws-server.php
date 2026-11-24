<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("The WebSocket server must be started from the command line.\n");
}

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

function ws_write_all($socket, string $data): bool
{
    $length = strlen($data);
    $written = 0;
    $emptyWrites = 0;

    while ($written < $length) {
        $chunk = @fwrite($socket, substr($data, $written));
        if ($chunk === false) {
            return false;
        }
        if ($chunk === 0) {
            $emptyWrites++;
            if ($emptyWrites > 1000) {
                return false;
            }
            usleep(1000);
            continue;
        }
        $emptyWrites = 0;
        $written += $chunk;
    }

    return true;
}

function ws_frame(string $payload, int $opcode = 0x1): string
{
    $length = strlen($payload);
    $header = chr(0x80 | ($opcode & 0x0f));

    if ($length < 126) {
        return $header . chr($length) . $payload;
    }

    if ($length < 65536) {
        return $header . chr(126) . pack('n', $length) . $payload;
    }

    $high = intdiv($length, 4294967296);
    $low = $length % 4294967296;

    return $header . chr(127) . pack('NN', $high, $low) . $payload;
}

function ws_send($socket, array $payload): bool
{
    try {
        return ws_write_all($socket, ws_frame(chat_json($payload)));
    } catch (Throwable $exception) {
        return false;
    }
}

function ws_send_error($socket, string $message, ?string $clientId = null): void
{
    $payload = ['type' => 'error', 'message' => $message];
    if ($clientId !== null && $clientId !== '') {
        $payload['client_id'] = $clientId;
    }
    ws_send($socket, $payload);
}

function ws_session_id_from_cookie(string $cookieHeader): ?string
{
    $cookieName = chat_config()['session']['name'];

    foreach (explode(';', $cookieHeader) as $cookie) {
        [$name, $value] = array_pad(explode('=', trim($cookie), 2), 2, '');
        if ($name === $cookieName && $value !== '') {
            return urldecode($value);
        }
    }

    return null;
}

function ws_user_from_cookie(string $cookieHeader): ?int
{
    $sessionId = ws_session_id_from_cookie($cookieHeader);
    if ($sessionId === null || !preg_match('/^[A-Za-z0-9,-]+$/', $sessionId)) {
        return null;
    }

    session_name(chat_config()['session']['name']);
    session_id($sessionId);
    session_start();
    $userId = chat_session_user_id();
    session_write_close();

    return $userId;
}

function ws_handshake($socket, string &$buffer): ?int
{
    $headerEnd = strpos($buffer, "\r\n\r\n");
    if ($headerEnd === false) {
        return 0;
    }

    $request = substr($buffer, 0, $headerEnd + 4);
    $buffer = substr($buffer, $headerEnd + 4);
    $lines = preg_split("/\r\n/", trim($request)) ?: [];
    $requestLine = array_shift($lines) ?: '';
    $headers = [];

    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    if (!str_contains(strtoupper($requestLine), 'GET ') || empty($headers['sec-websocket-key'])) {
        ws_write_all($socket, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
        return -1;
    }

    $userId = ws_user_from_cookie((string) ($headers['cookie'] ?? ''));
    if ($userId === null) {
        ws_write_all($socket, "HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
        return -1;
    }

    $accept = base64_encode(sha1(
        trim($headers['sec-websocket-key']) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
        true
    ));
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

    if (!ws_write_all($socket, $response)) {
        return -1;
    }

    ws_send($socket, [
        'type' => 'ready',
        'user_id' => $userId,
        'message' => 'WebSocket connection ready.',
    ]);

    return $userId;
}

function ws_decode_frame(string &$buffer): ?array
{
    if (strlen($buffer) < 2) {
        return null;
    }

    $first = ord($buffer[0]);
    $second = ord($buffer[1]);
    $fin = (bool) ($first & 0x80);
    $opcode = $first & 0x0f;
    $masked = (bool) ($second & 0x80);
    $length = $second & 0x7f;
    $offset = 2;

    if ($length === 126) {
        if (strlen($buffer) < $offset + 2) return null;
        $length = unpack('nlength', substr($buffer, $offset, 2))['length'];
        $offset += 2;
    } elseif ($length === 127) {
        if (strlen($buffer) < $offset + 8) return null;
        $parts = unpack('Nhigh/Nlow', substr($buffer, $offset, 8));
        $length = ($parts['high'] * 4294967296) + $parts['low'];
        $offset += 8;
    }

    if ($length > 10 * 1024 * 1024) {
        return ['fin' => true, 'opcode' => 0x8, 'payload' => ''];
    }

    $mask = '';
    if ($masked) {
        if (strlen($buffer) < $offset + 4) return null;
        $mask = substr($buffer, $offset, 4);
        $offset += 4;
    }

    if (strlen($buffer) < $offset + $length) {
        return null;
    }

    $payload = substr($buffer, $offset, $length);
    $buffer = substr($buffer, $offset + $length);

    if ($masked) {
        $unmasked = '';
        for ($index = 0; $index < $length; $index++) {
            $unmasked .= $payload[$index] ^ $mask[$index % 4];
        }
        $payload = $unmasked;
    }

    return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
}

function ws_close_client(array &$clients, array &$states, int $id): void
{
    if (!isset($states[$id])) {
        return;
    }

    $socket = $states[$id]['socket'];
    if (!empty($states[$id]['handshaken'])) {
        @ws_write_all($socket, ws_frame('', 0x8));
    }
    @fclose($socket);
    unset($clients[$id], $states[$id]);
}

function ws_broadcast(array &$states, array $userIds, array $payload): void
{
    foreach ($states as $state) {
        if (!$state['handshaken'] || !in_array((int) $state['user_id'], $userIds, true)) {
            continue;
        }
        ws_send($state['socket'], $payload);
    }
}

function ws_handle_packet($socket, int $userId, string $payload, PDO $pdo, array &$states): void
{
    $packet = json_decode($payload, true);
    if (!is_array($packet)) {
        ws_send_error($socket, 'Invalid WebSocket message.');
        return;
    }

    $type = (string) ($packet['type'] ?? '');

    if ($type === 'hello') {
        ws_send($socket, ['type' => 'ready', 'user_id' => $userId]);
        return;
    }

    if ($type === 'mark_read') {
        $otherUserId = filter_var($packet['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($otherUserId) {
            $statement = $pdo->prepare(
                "UPDATE messages SET status = 'read' WHERE sender_id = ? AND receiver_id = ? AND status = 'unread'"
            );
            $statement->execute([(int) $otherUserId, $userId]);
        }
        return;
    }

    if ($type !== 'send_message') {
        return;
    }

    $clientId = (string) ($packet['client_id'] ?? '');
    $receiverId = filter_var($packet['receiver_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $content = trim((string) ($packet['content'] ?? ''));

    $receiver = $receiverId ? chat_current_user($pdo, (int) $receiverId) : null;
    if (!$receiver || ($receiver['status'] ?? 'active') !== 'active') {
        ws_send_error($socket, 'Please choose a valid contact.', $clientId);
        return;
    }
    if ((int) $receiverId === $userId) {
        ws_send_error($socket, 'You cannot send a message to yourself.', $clientId);
        return;
    }
    if ($content === '' || mb_strlen($content) > 5000) {
        ws_send_error($socket, 'Message content is required and must be 5000 characters or fewer.', $clientId);
        return;
    }

    try {
        $message = chat_create_message($pdo, $userId, (int) $receiverId, $content);
        $outgoing = ['type' => 'message', 'data' => $message];
        if ($clientId !== '') {
            $outgoing['client_id'] = $clientId;
        }
        ws_broadcast($states, [$userId, (int) $receiverId], $outgoing);
    } catch (Throwable $exception) {
        error_log('[php-chat-app websocket] ' . $exception->getMessage());
        ws_send_error($socket, 'The message could not be saved.', $clientId);
    }
}

$config = chat_config()['websocket'];
$serverError = 0;
$serverErrorMessage = '';
$server = @stream_socket_server(
    sprintf('tcp://%s:%d', $config['host'], $config['port']),
    $serverError,
    $serverErrorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);
if ($server === false) {
    throw new RuntimeException(sprintf('Unable to start WebSocket server on %s:%d: %s', $config['host'], $config['port'], $serverErrorMessage));
}

stream_set_blocking($server, false);
$clients = [];
$states = [];
$pdo = chat_db();

fwrite(STDOUT, sprintf("PHP WebSocket chat server listening on %s:%d\n", $config['host'], $config['port']));
fwrite(STDOUT, "Press Ctrl+C to stop.\n");

while (true) {
    $read = [$server];
    foreach ($clients as $client) {
        $read[] = $client;
    }
    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, 1);

    if ($changed === false) {
        continue;
    }

    if (in_array($server, $read, true)) {
        $client = @stream_socket_accept($server, 0);
        if ($client !== false) {
            stream_set_blocking($client, false);
            $id = (int) $client;
            $clients[$id] = $client;
            $states[$id] = [
                'socket' => $client,
                'buffer' => '',
                'handshaken' => false,
                'user_id' => null,
                'fragment' => '',
                'fragment_opcode' => null,
            ];
        }
        $read = array_values(array_filter($read, static fn ($socket): bool => $socket !== $server));
    }

    foreach ($read as $socket) {
        $id = (int) $socket;
        if (!isset($states[$id])) {
            continue;
        }

        $chunk = @fread($socket, 8192);
        if ($chunk === false || $chunk === '') {
            ws_close_client($clients, $states, $id);
            continue;
        }

        $states[$id]['buffer'] .= $chunk;

        if (!$states[$id]['handshaken']) {
            $userId = ws_handshake($socket, $states[$id]['buffer']);
            if ($userId === 0) {
                continue;
            }
            if ($userId < 0) {
                ws_close_client($clients, $states, $id);
                continue;
            }
            $states[$id]['handshaken'] = true;
            $states[$id]['user_id'] = $userId;
            chat_touch_user($pdo, $userId);
        }

        while (($frame = ws_decode_frame($states[$id]['buffer'])) !== null) {
            $opcode = (int) $frame['opcode'];

            if ($opcode === 0x8) {
                ws_close_client($clients, $states, $id);
                break;
            }
            if ($opcode === 0x9) {
                @ws_write_all($socket, ws_frame($frame['payload'], 0xA));
                continue;
            }
            if ($opcode === 0xA) {
                continue;
            }

            if (!$frame['fin']) {
                $states[$id]['fragment'] .= $frame['payload'];
                $states[$id]['fragment_opcode'] = $opcode;
                continue;
            }

            $payload = $frame['payload'];
            if ($opcode === 0x0 && $states[$id]['fragment'] !== '') {
                $payload = $states[$id]['fragment'] . $payload;
                $opcode = (int) $states[$id]['fragment_opcode'];
                $states[$id]['fragment'] = '';
                $states[$id]['fragment_opcode'] = null;
            }

            if ($opcode === 0x1) {
                ws_handle_packet($socket, (int) $states[$id]['user_id'], $payload, $pdo, $states);
            }
        }
    }
}
