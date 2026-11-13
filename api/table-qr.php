<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(['error' => 'Method not allowed.'], 405);
}

require_roles(['owner']);
$db = lanay_db();

if ($method === 'GET') {
    $db->beginTransaction();
    $tables = $db->query(
        'SELECT table_id, table_number, seats, qr_token
         FROM dining_tables ORDER BY table_number FOR UPDATE'
    )->fetchAll();
    $update = $db->prepare('UPDATE dining_tables SET qr_token = ? WHERE table_id = ?');
    foreach ($tables as &$table) {
        if ($table['qr_token'] === null) {
            $table['qr_token'] = bin2hex(random_bytes(32));
            $update->execute([$table['qr_token'], $table['table_id']]);
        }
    }
    unset($table);
    $db->commit();

    json_response([
        'tables' => array_map(static function (array $table): array {
            return [
                'number' => (int) $table['table_number'],
                'seats' => (int) $table['seats'],
                'token' => $table['qr_token'],
            ];
        }, $tables),
    ]);
}

require_csrf();
$input = request_json();
$tableNumber = filter_var($input['tableNumber'] ?? null, FILTER_VALIDATE_INT);
if (($input['action'] ?? null) !== 'rotate' || !$tableNumber) {
    json_response(['error' => 'Choose a valid table to rotate its QR code.'], 422);
}

$token = bin2hex(random_bytes(32));
$rotate = $db->prepare('UPDATE dining_tables SET qr_token = ? WHERE table_number = ?');
$rotate->execute([$token, $tableNumber]);
if ($rotate->rowCount() !== 1) {
    json_response(['error' => 'Table was not found.'], 404);
}

json_response(['tableNumber' => (int) $tableNumber, 'token' => $token]);
