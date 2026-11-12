<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'Method not allowed.'], 405);
}

$input = request_json();
$role = $input['role'] ?? null;
$pin = $input['pin'] ?? null;
$roles = ['waiter', 'kitchen', 'cashier', 'owner'];

if (!is_string($role) || !in_array($role, $roles, true)
    || !is_string($pin) || !preg_match('/^\d{4}$/', $pin)) {
    json_response(['error' => 'Choose a role and enter a 4-digit PIN.'], 422);
}

$db = lanay_db();
$ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$lockoutSeconds = 120;
$attemptQuery = $db->prepare(
    'SELECT attempt_count, window_started_at, locked_until
     FROM login_attempts WHERE ip_address = ?'
);
$attemptQuery->execute([$ipAddress]);
$attempt = $attemptQuery->fetch();

if ($attempt && $attempt['locked_until'] !== null
    && new DateTimeImmutable($attempt['locked_until'], new DateTimeZone('UTC')) > $now) {
    $lockedUntil = new DateTimeImmutable($attempt['locked_until'], new DateTimeZone('UTC'));
    $retryAfter = max(1, $lockedUntil->getTimestamp() - $now->getTimestamp());
    if ($retryAfter > $lockoutSeconds) {
        $lockedUntil = $now->modify("+{$lockoutSeconds} seconds");
        $retryAfter = $lockoutSeconds;
        $updateLock = $db->prepare(
            'UPDATE login_attempts SET locked_until = ? WHERE ip_address = ?'
        );
        $updateLock->execute([$lockedUntil->format('Y-m-d H:i:s'), $ipAddress]);
    }

    json_response([
        'error' => 'Too many attempts. Wait for the countdown before trying again.',
        'retryAfter' => $retryAfter,
    ], 429);
}

$windowExpired = !$attempt
    || $now->getTimestamp() - (new DateTimeImmutable($attempt['window_started_at'], new DateTimeZone('UTC')))->getTimestamp() >= 900;
if ($windowExpired || ($attempt && $attempt['locked_until'] !== null)) {
    $attempt = ['attempt_count' => 0];
}

$staffQuery = $db->prepare(
    'SELECT staff_id, display_name, role, pin_hash
     FROM staff WHERE role = ? AND is_active = 1'
);
$staffQuery->execute([$role]);
$staff = null;
foreach ($staffQuery->fetchAll() as $candidate) {
    if (password_verify($pin, $candidate['pin_hash'])) {
        $staff = $candidate;
        break;
    }
}

if ($staff === null) {
    $attemptCount = (int) $attempt['attempt_count'] + 1;
    $windowStartedAt = $windowExpired
        ? $now->format('Y-m-d H:i:s')
        : $attempt['window_started_at'];
    $lockedUntil = $attemptCount >= 5
        ? $now->modify("+{$lockoutSeconds} seconds")->format('Y-m-d H:i:s')
        : null;
    $saveAttempt = $db->prepare(
        'INSERT INTO login_attempts (ip_address, attempt_count, window_started_at, locked_until)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            attempt_count = VALUES(attempt_count),
            window_started_at = VALUES(window_started_at),
            locked_until = VALUES(locked_until)'
    );
    $saveAttempt->execute([$ipAddress, $attemptCount, $windowStartedAt, $lockedUntil]);

    $error = [
        'error' => $lockedUntil === null
            ? 'Incorrect role or PIN.'
            : 'Too many attempts. Wait for the countdown before trying again.',
    ];
    if ($lockedUntil !== null) {
        $error['retryAfter'] = $lockoutSeconds;
    }
    json_response($error, $lockedUntil === null ? 401 : 429);
}

$db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ipAddress]);
session_regenerate_id(true);
$_SESSION['staff'] = [
    'id' => (int) $staff['staff_id'],
    'name' => $staff['display_name'],
    'role' => $staff['role'],
];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$routes = [
    'waiter' => 'tables.html',
    'kitchen' => 'kitchen.html',
    'cashier' => '../screens/cashier/orders.html',
    'owner' => '../screens/owner/reports.html',
];

json_response([
    'staff' => $_SESSION['staff'],
    'csrfToken' => $_SESSION['csrf_token'],
    'redirect' => $routes[$staff['role']],
]);
