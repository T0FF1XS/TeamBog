<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function payment_amount_cents(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    $value = (string) $value;
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $value)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}

function payment_decimal(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(['error' => 'Method not allowed.'], 405);
}

$staff = require_roles(['cashier', 'owner']);
$db = lanay_db();

if ($method === 'GET') {
    $orderId = filter_var($_GET['orderId'] ?? null, FILTER_VALIDATE_INT);
    if (!$orderId) {
        json_response(['error' => 'A valid order ID is required.'], 422);
    }

    $query = $db->prepare(
        'SELECT p.payment_id, p.order_id, p.method, p.amount, p.amount_received,
                p.change_given, p.reference, p.created_at, o.status, o.guest_count,
                t.table_number, s.display_name AS cashier
         FROM payments p
         JOIN orders o ON o.order_id = p.order_id
         JOIN dining_tables t ON t.table_id = o.table_id
         LEFT JOIN staff s ON s.staff_id = p.received_by
         WHERE p.order_id = ?'
    );
    $query->execute([$orderId]);
    $payment = $query->fetch();
    if (!$payment) {
        json_response(['error' => 'Payment was not found.'], 404);
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
        'id' => (int) $payment['payment_id'],
        'orderId' => (int) $payment['order_id'],
        'orderStatus' => $payment['status'],
        'tableNumber' => (int) $payment['table_number'],
        'guests' => (int) $payment['guest_count'],
        'method' => $payment['method'],
        'amount' => $payment['amount'],
        'amountReceived' => $payment['amount_received'],
        'change' => $payment['change_given'],
        'reference' => $payment['reference'],
        'createdAt' => $payment['created_at'],
        'cashier' => $payment['cashier'],
        'items' => $items,
    ]);
}

require_csrf();
$input = request_json();
$orderId = filter_var($input['orderId'] ?? null, FILTER_VALIDATE_INT);
$paymentMethod = $input['method'] ?? null;
$reference = $input['reference'] ?? '';
if (!$orderId || !is_string($paymentMethod)
    || !in_array($paymentMethod, ['cash', 'gcash', 'card'], true)
    || !is_string($reference) || !preg_match('/^.{0,120}$/us', $reference)) {
    json_response(['error' => 'Provide a valid order, payment method, and reference.'], 422);
}
$reference = trim($reference);

$db->beginTransaction();
try {
    $orderQuery = $db->prepare(
        'SELECT order_id, table_id, status, total FROM orders WHERE order_id = ? FOR UPDATE'
    );
    $orderQuery->execute([$orderId]);
    $order = $orderQuery->fetch();
    if (!$order) {
        $db->rollBack();
        json_response(['error' => 'Order was not found.'], 404);
    }
    if ($order['status'] !== 'served') {
        $db->rollBack();
        json_response(['error' => 'Only served orders can be paid.'], 409);
    }

    $totalCents = payment_amount_cents((string) $order['total']);
    if ($totalCents === null || $totalCents <= 0) {
        $db->rollBack();
        json_response(['error' => 'Order total is invalid. Ask an owner to review this order.'], 409);
    }
    if ($paymentMethod === 'cash') {
        $receivedCents = payment_amount_cents($input['amountReceived'] ?? null);
        if ($receivedCents === null || $receivedCents < $totalCents) {
            $db->rollBack();
            json_response(['error' => 'Cash received must be at least the amount due.'], 422);
        }
    } else {
        $receivedCents = $totalCents;
    }

    $insert = $db->prepare(
        'INSERT INTO payments
            (order_id, received_by, method, amount, amount_received, change_given, reference)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $orderId,
        $staff['id'],
        $paymentMethod,
        payment_decimal($totalCents),
        payment_decimal($receivedCents),
        payment_decimal($receivedCents - $totalCents),
        $reference,
    ]);
    $paymentId = (int) $db->lastInsertId();
    $db->prepare("UPDATE orders SET status = 'paid' WHERE order_id = ?")->execute([$orderId]);

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
    $db->prepare('UPDATE dining_tables SET status = ? WHERE table_id = ?')
        ->execute([$tableStatus, $tableId]);
    $db->commit();
} catch (PDOException $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($error->getCode() === '23000') {
        json_response(['error' => 'This order already has a recorded payment.'], 409);
    }
    error_log('Lanay payment recording failed: ' . $error->getMessage());
    json_response(['error' => 'Payment could not be recorded. Please try again.'], 500);
}

json_response([
    'id' => $paymentId,
    'orderId' => $orderId,
    'method' => $paymentMethod,
    'amount' => payment_decimal($totalCents),
    'amountReceived' => payment_decimal($receivedCents),
    'change' => payment_decimal($receivedCents - $totalCents),
    'status' => 'paid',
], 201);
