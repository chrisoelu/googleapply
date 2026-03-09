<?php

$configPath = __DIR__ . '/../config.php';

if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing backend/config.php. Copy config.sample.php and fill values.']);
    exit;
}

$config = require $configPath;
$frontendUrl = (string) ($config['frontend_url'] ?? '');
$isHttpsFrontend = str_starts_with(strtolower($frontendUrl), 'https://');

session_name($config['session_name'] ?? 'google_login_test_session');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => $isHttpsFrontend,
]);

function app_config(string $key, $default = null)
{
    global $config;
    return $config[$key] ?? $default;
}

function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $frontend = rtrim((string) app_config('frontend_url', ''), '/');

    if ($origin !== '' && $origin === $frontend) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

function handle_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function http_post_form(string $url, array $payload): array
{
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($payload),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ];

    $context = stream_context_create($options);
    $body = file_get_contents($url, false, $context);

    if ($body === false) {
        throw new RuntimeException('Token request failed.');
    }

    return decode_http_response($http_response_header ?? [], $body);
}

function http_get_json(string $url, array $headers = []): array
{
    $headerLines = [];
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }

    $options = [
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ];

    $context = stream_context_create($options);
    $body = file_get_contents($url, false, $context);

    if ($body === false) {
        throw new RuntimeException('GET request failed.');
    }

    return decode_http_response($http_response_header ?? [], $body);
}

function decode_http_response(array $responseHeaders, string $body): array
{
    $statusLine = $responseHeaders[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $status = isset($matches[1]) ? (int) $matches[1] : 0;

    $json = json_decode($body, true);
    if (!is_array($json)) {
        $json = [];
    }

    return [
        'status' => $status,
        'json' => $json,
        'raw' => $body,
    ];
}

function frontend_redirect(string $path = ''): void
{
    $base = rtrim((string) app_config('frontend_url', 'http://localhost:5173'), '/');
    header('Location: ' . $base . $path);
    exit;
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        return null;
    }

    $sub = $user['sub'] ?? null;
    if (!is_string($sub) || $sub === '') {
        return null;
    }

    return $user;
}

function require_authenticated_user(): array
{
    $user = current_user();
    if ($user === null) {
        json_response(['error' => 'Not authenticated'], 401);
    }

    return $user;
}

function db_connection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = app_config('db');
    if (!is_array($db)) {
        throw new RuntimeException('Database config missing.');
    }

    $host = (string) ($db['host'] ?? '');
    $port = (int) ($db['port'] ?? 3306);
    $database = (string) ($db['database'] ?? '');
    $username = (string) ($db['username'] ?? '');
    $password = (string) ($db['password'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');

    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('Database config incomplete.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function ensure_user_forms_table(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS user_forms (
    google_sub VARCHAR(191) NOT NULL PRIMARY KEY,
    email VARCHAR(320) NULL,
    first_name VARCHAR(191) NULL,
    last_name VARCHAR(191) NULL,
    approximate_address TEXT NULL,
    preferred_contact TEXT NULL,
    social_media TEXT NULL,
    other_notes TEXT NULL,
    video_blob LONGBLOB NULL,
    video_mime VARCHAR(100) NULL,
    video_filename VARCHAR(255) NULL,
    video_size BIGINT UNSIGNED NULL,
    video_uploaded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);
    $initialized = true;
}

function sync_google_user_to_form(PDO $pdo, array $user): void
{
    ensure_user_forms_table($pdo);

    $sub = (string) ($user['sub'] ?? '');
    if ($sub === '') {
        throw new RuntimeException('Missing Google user sub.');
    }

    $email = normalize_text($user['email'] ?? null);
    $firstName = normalize_text($user['given_name'] ?? null);
    $lastName = normalize_text($user['family_name'] ?? null);

    $insertStmt = $pdo->prepare(
        'INSERT INTO user_forms (google_sub, email, first_name, last_name)
         VALUES (:google_sub, :email, :first_name, :last_name)
         ON DUPLICATE KEY UPDATE google_sub = google_sub'
    );
    $insertStmt->execute([
        ':google_sub' => $sub,
        ':email' => $email,
        ':first_name' => $firstName,
        ':last_name' => $lastName,
    ]);

    $fillStmt = $pdo->prepare(
        'UPDATE user_forms
         SET email = COALESCE(email, :email),
             first_name = COALESCE(first_name, :first_name),
             last_name = COALESCE(last_name, :last_name)
         WHERE google_sub = :google_sub'
    );
    $fillStmt->execute([
        ':google_sub' => $sub,
        ':email' => $email,
        ':first_name' => $firstName,
        ':last_name' => $lastName,
    ]);
}

function normalize_text($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

function map_upload_error(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE => 'Datei ueberschreitet upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE => 'Datei ueberschreitet MAX_FILE_SIZE.',
        UPLOAD_ERR_PARTIAL => 'Datei wurde nur teilweise hochgeladen.',
        UPLOAD_ERR_NO_FILE => 'Keine Datei hochgeladen.',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporaeres Verzeichnis fehlt.',
        UPLOAD_ERR_CANT_WRITE => 'Datei konnte nicht geschrieben werden.',
        UPLOAD_ERR_EXTENSION => 'Upload wurde durch eine Erweiterung gestoppt.',
        default => 'Unbekannter Upload-Fehler.',
    };
}

function detect_video_duration_seconds(string $tmpPath): ?float
{
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return null;
    }

    if (stripos(PHP_OS, 'WIN') === 0) {
        $probeCheck = @shell_exec('where ffprobe');
    } else {
        $probeCheck = @shell_exec('command -v ffprobe');
    }

    if (!is_string($probeCheck) || trim($probeCheck) === '') {
        return null;
    }

    $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($tmpPath);
    $output = @shell_exec($cmd);

    if (!is_string($output)) {
        return null;
    }

    $duration = (float) trim($output);
    return $duration > 0 ? $duration : null;
}
