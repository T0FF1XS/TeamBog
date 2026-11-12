<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inventory_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_roles(['waiter', 'kitchen', 'cashier', 'owner']);
    $status = $_GET['status'] ?? null;
    $allowedStatuses = ['new', 'preparing', 'ready', 'served', 'paid', 'cancelled'];
    if ($status !== null && (!is_string($status) || !in_array($status, $allowedStatuses, true))) {
        json_response(['error' => 'Invalid order status.'], 422);
    }

    $db = lanay_db();
    if ($status === null) {
        $query = $db->query(
            "SELECT o.order_id, t.table_number, o.guest_count, o.status, o.source,
                    o.kitchen_note, o.total, o.created_at, s.display_name AS created_by
             FROM orders o
             JOIN dining_tables t ON t.table_id = o.table_id
             LEFT JOIN staff s ON s.staff_id = o.created_by
             ORDER BY o.created_at DESC LIMIT 100"
        );
    } else {
        $query = $db->prepare(
            "SELECT o.order_id, t.table_number, o.guest_count, o.status, o.source,
                    o.kitchen_note, o.total, o.created_at, s.display_name AS created_by
             FROM orders o
             JOIN dining_tables t ON t.table_id = o.table_id
             LEFT JOIN staff s ON s.staff_id = o.created_by
             WHERE o.status = ?
             ORDER BY o.created_at ASC LIMIT 100"
        );
        $query->execute([$status]);
    }
    $orders = $query->fetchAll();
    if ($orders) {
        $ids = array_column($orders, 'order_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $lines = $db->prepare(
            "SELECT order_id, item_name AS name, unit_price AS price, quantity
             FROM order_items WHERE order_id IN ({$placeholders})
             ORDER BY order_item_id"
        );
        $lines->execute($ids);
        $itemsByOrder = [];
        foreach ($lines->fetchAll() as $line) {
            $itemsByOrder[(int) $line['order_id']][] = [
                'name' => $line['name'],
                'price' => $line['price'],
                'quantity' => (int) $line['quantity'],
            ];
        }

        foreach ($orders as &$order) {
            $order['id'] = (int) $order['order_id'];
            $order['tableNumber'] = (int) $order['table_number'];
            $order['guests'] = (int) $order['guest_count'];
            $order['items'] = $itemsByOrder[(int) $order['order_id']] ?? [];
            unset($order['order_id'], $order['table_number'], $order['guest_count']);
        }
        unset($order);
    }

    json_response(['orders' => $orders]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_response(['error' => 'Method not allowed.'], 405);
}

$input = request_json();
$isCustomerOrder = array_key_exists('tableToken', $input);
$staff = null;
$tableNumber = null;
if ($isCustomerOrder) {
    $tableToken = $input['tableToken'];
    if (!is_string($tableToken) || !preg_match('/^[a-f0-9]{64}$/D', $tableToken)) {
        json_response(['error' => 'A valid table QR code is required.'], 422);
    }
} else {
    $staff = require_roles(['waiter', 'owner']);
    require_csrf();
    $tableNumber = filter_var($input['tableNumber'] ?? null, FILTER_VALIDATE_INT);
}
$guestCount = filter_var($input['guestCount'] ?? null, FILTER_VALIDATE_INT);
$note = $input['note'] ?? '';
$requestedItems = $input['items'] ?? null;

if ((!$isCustomerOrder && (!$tableNumber || $tableNumber > 99))
    || !$guestCount || $guestCount > 99
    || !is_string($note) || !preg_match('/^.{0,500}$/us', $note)
    || !is_array($requestedItems) || count($requestedItems) < 1 || count($requestedItems) > 50) {
    json_response(['error' => 'Provide a valid table, guest count, note, and at least one menu item.'], 422);
}

$quantities = [];
foreach ($requestedItems as $requestedItem) {
    if (!is_array($requestedItem)) {
        json_response(['error' => 'Each order item must include a valid item ID and quantity.'], 422);
    }
    $itemId = filter_var($requestedItem['itemId'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($requestedItem['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!$itemId || !$quantity || $quantity > 99) {
        json_response(['error' => 'Each order item must include a valid item ID and quantity.'], 422);
    }
    $quantities[$itemId] = ($quantities[$itemId] ?? 0) + $quantity;
    if ($quantities[$itemId] > 99) {
        json_response(['error' => 'Item quantity cannot exceed 99.'], 422);
    }
}

$maxTotalCents = 9999999999;
$db = lanay_db();
try {
    $db->beginTransaction();
    $tableQuery = $isCustomerOrder
        ? $db->prepare('SELECT table_id, table_number, seats FROM dining_tables WHERE qr_token = ? FOR UPDATE')
        : $db->prepare('SELECT table_id, table_number, seats FROM dining_tables WHERE table_number = ? FOR UPDATE');
    $tableQuery->execute([$isCustomerOrder ? $tableToken : $tableNumber]);
    $table = $tableQuery->fetch();
    if (!$table) {
        $db->rollBack();
        json_response(['error' => 'Table was not found.'], 404);
    }
    if ($guestCount > (int) $table['seats']) {
        $db->rollBack();
        json_response(['error' => 'Guest count exceeds this table’s capacity.'], 422);
    }
    $tableId = (int) $table['table_id'];
    $tableNumber = (int) $table['table_number'];

    $itemIds = array_keys($quantities);
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $menuQuery = $db->prepare(
        "SELECT menu_item_id, name, price, portions_left, is_available
         FROM menu_items
         WHERE menu_item_id IN ({$placeholders}) AND is_active = 1
         FOR UPDATE"
    );
    $menuQuery->execute($itemIds);
    $menuItems = [];
    foreach ($menuQuery->fetchAll() as $item) {
        $menuItems[(int) $item['menu_item_id']] = $item;
    }
    if (count($menuItems) !== count($quantities)) {
        $db->rollBack();
        json_response(['error' => 'One or more menu items are no longer available. Refresh the menu.'], 409);
    }

    $ingredientRequirements = [];
    $recipeQuery = $db->prepare(
        "SELECT r.menu_item_id, i.ingredient_id, i.name, i.stock_quantity,
                i.is_active, r.quantity_per_portion
         FROM menu_item_ingredients r
         JOIN ingredients i ON i.ingredient_id = r.ingredient_id
         WHERE r.menu_item_id IN ({$placeholders})
         ORDER BY i.ingredient_id, r.menu_item_id
         FOR UPDATE"
    );
    $recipeQuery->execute($itemIds);
    foreach ($recipeQuery->fetchAll() as $recipe) {
        $ingredientId = (int) $recipe['ingredient_id'];
        $requiredMilli = inventory_milli((string) $recipe['quantity_per_portion'])
            * $quantities[(int) $recipe['menu_item_id']];
        if (!isset($ingredientRequirements[$ingredientId])) {
            $ingredientRequirements[$ingredientId] = [
                'name' => $recipe['name'],
                'stock' => (string) $recipe['stock_quantity'],
                'active' => (bool) $recipe['is_active'],
                'required' => 0,
            ];
        }
        $ingredientRequirements[$ingredientId]['required'] += $requiredMilli;
    }
    foreach ($ingredientRequirements as $ingredient) {
        if (!$ingredient['active']
            || inventory_milli($ingredient['stock']) < $ingredient['required']) {
            $db->rollBack();
            json_response(['error' => $ingredient['name'] . ' does not have enough stock for this order.'], 409);
        }
    }

    $totalCents = 0;
    foreach ($quantities as $itemId => $quantity) {
        $item = $menuItems[$itemId];
        if (!(bool) $item['is_available']
            || ($item['portions_left'] !== null && (int) $item['portions_left'] < $quantity)) {
            $db->rollBack();
            json_response(['error' => $item['name'] . ' is no longer available in that quantity.'], 409);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $item['price'], 2), 2, '00');
        $totalCents += ((int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0')) * $quantity;
        if ($totalCents > $maxTotalCents) {
            $db->rollBack();
            json_response(['error' => 'Order total exceeds the supported limit.'], 422);
        }
    }

    $total = intdiv($totalCents, 100) . '.' . str_pad((string) ($totalCents % 100), 2, '0', STR_PAD_LEFT);
    $trackingToken = $isCustomerOrder ? bin2hex(random_bytes(32)) : null;
    $trackingTokenHash = $trackingToken === null ? null : hash('sha256', $trackingToken);
    $insertOrder = $db->prepare(
        "INSERT INTO orders
            (table_id, created_by, source, customer_tracking_token_hash,
             guest_count, status, kitchen_note, total)
         VALUES (?, ?, ?, ?, ?, 'new', ?, ?)"
    );
    $insertOrder->execute([
        $tableId,
        $staff['id'] ?? null,
        $isCustomerOrder ? 'qr' : 'staff',
        $trackingTokenHash,
        $guestCount,
        $note,
        $total,
    ]);
    $orderId = (int) $db->lastInsertId();

    $insertLine = $db->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity)
         VALUES (?, ?, ?, ?, ?)'
    );
    $updatePortions = $db->prepare(
        'UPDATE menu_items SET portions_left = portions_left - ?
         WHERE menu_item_id = ? AND portions_left IS NOT NULL'
    );
    $disableSoldOut = $db->prepare(
        'UPDATE menu_items SET is_available = 0
         WHERE menu_item_id = ? AND portions_left = 0'
    );
    foreach ($quantities as $itemId => $quantity) {
        $item = $menuItems[$itemId];
        $insertLine->execute([$orderId, $itemId, $item['name'], $item['price'], $quantity]);
        if ($item['portions_left'] !== null) {
            $updatePortions->execute([$quantity, $itemId]);
            $disableSoldOut->execute([$itemId]);
        }
    }
    $updateIngredientStock = $db->prepare(
        'UPDATE ingredients
         SET stock_quantity = stock_quantity - ?
         WHERE ingredient_id = ? AND is_active = 1 AND stock_quantity >= ?'
    );
    $insertMovement = $db->prepare(
        "INSERT INTO inventory_movements
            (ingredient_id, order_id, staff_id, movement_type, quantity_change, note)
         VALUES (?, ?, ?, 'order', ?, ?)"
    );
    foreach ($ingredientRequirements as $ingredientId => $ingredient) {
        $amount = inventory_decimal($ingredient['required']);
        $updateIngredientStock->execute([$amount, $ingredientId, $amount]);
        if ($updateIngredientStock->rowCount() !== 1) {
            $db->rollBack();
            json_response(['error' => $ingredient['name'] . ' does not have enough stock for this order.'], 409);
        }
        $insertMovement->execute([
            $ingredientId,
            $orderId,
            $staff['id'] ?? null,
            '-' . $amount,
            'Order #' . $orderId,
        ]);
    }
    $db->prepare("UPDATE dining_tables SET status = 'ordering' WHERE table_id = ?")->execute([$tableId]);
    $db->commit();
} catch (PDOException $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Lanay order creation failed: ' . $error->getMessage());
    json_response(['error' => 'Order could not be saved. Please try again.'], 500);
}

json_response([
    'id' => $orderId,
    'tableNumber' => $tableNumber,
    'status' => 'new',
    'total' => $total,
    'trackingToken' => $trackingToken,
], 201);
