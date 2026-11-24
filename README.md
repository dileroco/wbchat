# PHP WebSocket Chat

This is a standalone PHP version of the React/Laravel chat UI. It keeps the same authentication screens, contact sidebar, profile modal, conversation layout, message bubbles, unread counts, and responsive styling.

## Run it in XAMPP

1. Start Apache in XAMPP.
2. Open `http://localhost/php-chat-app/`.
3. In a second terminal, start the WebSocket process, or double-click `start-websocket.bat`:

```powershell
cd C:\xampp\htdocs\php-chat-app
php ws-server.php
```

The page works with HTTP fallback while the socket process is offline, but the `php ws-server.php` process is required for live message delivery between open chat windows.

The app is configured to use the XAMPP MariaDB/MySQL instance at `127.0.0.1:3306` through the local `.env` file. The `.env` file is ignored by Git; copy `.env.example` and adjust the credentials for another machine.

## Administrator dashboard

Register a normal account first, then promote it from a terminal:

```powershell
cd C:\xampp\htdocs\php-chat-app
php make-admin.php your_username
```

Log in again and open the **Admin** link in the chat. The dashboard provides overview metrics, user search and access controls, report/message moderation, and database/WebSocket/storage health checks. Messages can be reported from the conversation by hovering a received message.

The MySQL database is `php_websocket_chat`. The application creates or updates its tables automatically on first connection. The one-time `migrate-sqlite-to-mysql.php` command copies existing SQLite users, messages, and reports while preserving their IDs; it refuses to run against non-empty MySQL tables.

## Run without Apache

The built-in PHP server can serve the app for local testing:

```powershell
cd C:\xampp\htdocs\php-chat-app
php -S 127.0.0.1:8000
php ws-server.php
```

Then visit `http://127.0.0.1:8000/`.

## Project pieces

- `index.php` renders the login, registration, and chat screens.
- `api.php` provides session-authenticated JSON endpoints.
- `admin.php` and `admin-api.php` provide the protected administration dashboard.
- `ws-server.php` is a dependency-free native PHP WebSocket server.
- `make-admin.php` promotes an existing account to administrator from the CLI.
- `migrate-sqlite-to-mysql.php` migrates the legacy SQLite data into MySQL from the CLI.
- `assets/app.js` preserves the React app's interactions in browser JavaScript and uses WebSockets for message delivery.
- `bootstrap.php` provides PDO, validation helpers, session handling, and automatic schema creation.
