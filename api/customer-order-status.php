<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_response(['error' => 'Method not allowed.'], 405);
}

$orderId = filter_var($_GET['orderId'] ?? null, FILTER_VALIDATE_INT);
$token = $_GET['token'] ?? null;
if (!$orderId || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
    json_response(['error' => 'A valid order tracking link is required.'], 422);
}

$db = lanay_db();
$orderQuery = $db->prepare(
    "SELECT o.order_id, o.status, o.total, o.created_at, t.table_number
     FROM orders o
     JOIN dining_tables t ON t.table_id = o.table_id
     WHERE o.order_id = ? AND o.source = 'qr' AND o.customer_tracking_token_hash = ?"
);
$orderQuery->execute([$orderId, hash('sha256', $token)]);
$order = $orderQuery->fetch();
if (!$order) {
    json_response(['error' => 'Order tracking link was not found.'], 404);
}

$itemsQuery = $db->prepare(
    'SELECT item_name AS name, unit_price AS price, quantity
     FROM order_items WHERE order_id = ? ORDER BY order_item_id'
);
$itemsQuery->execute([$orderId]);
$items = array_map(static function (array $item): array {
    return [
        'name' => $item['name'],
        'price' => $item['price'],
        'quantity' => (int) $item['quantity'],
    ];
}, $itemsQuery->fetchAll());

json_response([
    'id' => (int) $order['order_id'],
    'status' => $order['status'],
    'total' => $order['total'],
    'tableNumber' => (int) $order['table_number'],
    'createdAt' => $order['created_at'],
    'items' => $items,
]);
