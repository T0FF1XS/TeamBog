<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    session_name('lanay_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        json_response(['error' => 'Content-Type must be application/json.'], 415);
    }

    try {
        $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(['error' => 'Request body must contain valid JSON.'], 400);
    }

    if (!is_array($data)) {
        json_response(['error' => 'Request body must be a JSON object.'], 400);
    }

    return $data;
}

function lanay_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $localConfigPath = dirname(__DIR__) . '/config.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    if (!is_array($localConfig) || !is_array($localConfig['database'] ?? [])) {
        error_log('Lanay local database configuration must return a database array.');
        json_response(['error' => 'Database configuration is invalid.'], 500);
    }
    $databaseConfig = $localConfig['database'] ?? [];
    $host = getenv('LANAY_DB_HOST') ?: ($databaseConfig['host'] ?? '127.0.0.1');
    $name = getenv('LANAY_DB_NAME') ?: ($databaseConfig['name'] ?? 'lanay_db');
    $user = getenv('LANAY_DB_USER') ?: ($databaseConfig['user'] ?? 'root');
    $password = getenv('LANAY_DB_PASSWORD') ?: ($databaseConfig['password'] ?? '');

    try {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $error) {
        error_log('Lanay database connection failed: ' . $error->getMessage());
        json_response(['error' => 'Database is unavailable. Check the XAMPP database setup.'], 503);
    }

    return $pdo;
}

function current_staff(): array
{
    $staff = $_SESSION['staff'] ?? null;
    $staffId = is_array($staff) ? filter_var($staff['id'] ?? null, FILTER_VALIDATE_INT) : false;
    if (!$staffId) {
        json_response(['error' => 'Sign in is required.'], 401);
    }

    $query = lanay_db()->prepare(
        'SELECT display_name, role, is_active FROM staff WHERE staff_id = ?'
    );
    $query->execute([$staffId]);
    $account = $query->fetch();
    if (!$account || !(bool) $account['is_active']) {
        unset($_SESSION['staff'], $_SESSION['csrf_token']);
        json_response(['error' => 'This staff account is inactive. Sign in with an active account.'], 401);
    }

    $_SESSION['staff'] = [
        'id' => (int) $staffId,
        'name' => $account['display_name'],
        'role' => $account['role'],
    ];

    return $_SESSION['staff'];
}

function require_roles(array $roles): array
{
    $staff = current_staff();
    if (!in_array($staff['role'], $roles, true)) {
        json_response(['error' => 'Your role is not allowed to perform this action.'], 403);
    }

    return $staff;
}

function require_csrf(): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($expected) || $expected === '' || !is_string($provided)
        || !hash_equals($expected, $provided)) {
        json_response(['error' => 'Request verification failed. Refresh and try again.'], 403);
    }
}
