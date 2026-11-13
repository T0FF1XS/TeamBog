<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'Method not allowed.'], 405);
}

$staff = require_roles(['kitchen', 'waiter', 'owner']);
require_csrf();
$input = request_json();
$orderId = filter_var($input['orderId'] ?? null, FILTER_VALIDATE_INT);
$action = $input['action'] ?? null;

if (!$orderId || !is_string($action)) {
    json_response(['error' => 'A valid order ID and action are required.'], 422);
}

$transitions = [
    'start' => ['new', 'preparing', ['kitchen', 'owner']],
    'ready' => ['preparing', 'ready', ['kitchen', 'owner']],
    'served' => ['ready', 'served', ['waiter', 'owner']],
];
if (!isset($transitions[$action])) {
    json_response(['error' => 'Unknown order action.'], 422);
}
[$from, $to, $allowedRoles] = $transitions[$action];
if (!in_array($staff['role'], $allowedRoles, true)) {
    json_response(['error' => 'Your role is not allowed to perform this order action.'], 403);
}

$db = lanay_db();
try {
    $db->beginTransaction();
    $findOrder = $db->prepare('SELECT table_id, status FROM orders WHERE order_id = ? FOR UPDATE');
    $findOrder->execute([$orderId]);
    $order = $findOrder->fetch();
    if (!$order) {
        $db->rollBack();
        json_response(['error' => 'Order was not found.'], 404);
    }
    if ($order['status'] !== $from) {
        $db->rollBack();
        json_response(['error' => 'Order status has changed. Refresh and try again.'], 409);
    }

    $db->prepare('UPDATE orders SET status = ? WHERE order_id = ?')->execute([$to, $orderId]);
    $tableId = (int) $order['table_id'];
    $activeQuery = $db->prepare(
        "SELECT
            SUM(status IN ('new', 'preparing', 'ready')) AS ordering_count,
            SUM(status = 'served') AS eating_count
         FROM orders WHERE table_id = ?"
    );
    $activeQuery->execute([$tableId]);
    $counts = $activeQuery->fetch();
    $tableStatus = (int) ($counts['ordering_count'] ?? 0) > 0
        ? 'ordering'
        : ((int) ($counts['eating_count'] ?? 0) > 0 ? 'eating' : 'free');
    $db->prepare('UPDATE dining_tables SET status = ? WHERE table_id = ?')->execute([$tableStatus, $tableId]);
    $db->commit();
} catch (PDOException $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Lanay order status update failed: ' . $error->getMessage());
    json_response(['error' => 'Order status could not be updated. Please try again.'], 500);
}

json_response(['id' => $orderId, 'status' => $to]);
