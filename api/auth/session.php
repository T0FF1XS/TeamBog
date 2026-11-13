<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_response(['error' => 'Method not allowed.'], 405);
}

$staff = current_staff();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
json_response([
    'staff' => $staff,
    'csrfToken' => $_SESSION['csrf_token'],
]);
