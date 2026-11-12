<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/menu_data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_response(['error' => 'Method not allowed.'], 405);
}

$token = $_GET['token'] ?? null;
if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
    json_response(['error' => 'A valid table QR code is required.'], 422);
}

$db = lanay_db();
$tableQuery = $db->prepare(
    'SELECT table_number, seats FROM dining_tables WHERE qr_token = ?'
);
$tableQuery->execute([$token]);
$table = $tableQuery->fetch();
if (!$table) {
    json_response(['error' => 'This table QR code is no longer valid. Ask staff for a new code.'], 404);
}

json_response([
    'tableNumber' => (int) $table['table_number'],
    'seats' => (int) $table['seats'],
    'items' => fetch_menu_items($db),
]);
