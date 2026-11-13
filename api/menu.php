<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/menu_data.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $includeArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
    if ($includeArchived) {
        require_roles(['owner']);
    }

    json_response(['items' => fetch_menu_items(lanay_db(), $includeArchived)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_response(['error' => 'Method not allowed.'], 405);
}

require_roles(['owner']);
require_csrf();
$input = request_json();
$action = $input['action'] ?? 'save';
$db = lanay_db();

if ($action === 'availability') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $available = $input['available'] ?? null;
    if (!$id || !is_bool($available)) {
        json_response(['error' => 'A valid item ID and availability value are required.'], 422);
    }

    if ($available) {
        $portions = $db->prepare(
            'SELECT portions_left FROM menu_items WHERE menu_item_id = ? AND is_active = 1'
        );
        $portions->execute([$id]);
        $portionsLeft = $portions->fetchColumn();
        if ($portionsLeft === false) {
            json_response(['error' => 'Menu item was not found.'], 404);
        }
        if ($portionsLeft !== null && (int) $portionsLeft === 0) {
            json_response(['error' => 'Add portions before making this item available.'], 409);
        }
    }

    $update = $db->prepare(
        'UPDATE menu_items SET is_available = ? WHERE menu_item_id = ? AND is_active = 1'
    );
    $update->execute([$available ? 1 : 0, $id]);
    if ($update->rowCount() !== 1) {
        $exists = $db->prepare('SELECT 1 FROM menu_items WHERE menu_item_id = ? AND is_active = 1');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            json_response(['error' => 'Menu item was not found.'], 404);
        }
    }

    json_response(['ok' => true]);
}

if ($action === 'archive') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(['error' => 'A valid item ID is required.'], 422);
    }

    $archive = $db->prepare(
        'UPDATE menu_items SET is_active = 0 WHERE menu_item_id = ? AND is_active = 1'
    );
    $archive->execute([$id]);
    if ($archive->rowCount() !== 1) {
        json_response(['error' => 'Menu item was not found or is already archived.'], 404);
    }

    json_response(['ok' => true]);
}

if ($action !== 'save') {
    json_response(['error' => 'Unknown menu action.'], 422);
}

$id = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : null;
$name = trim((string) ($input['name'] ?? ''));
$categoryId = filter_var($input['categoryId'] ?? null, FILTER_VALIDATE_INT);
$price = $input['price'] ?? null;
$portionsLeft = $input['portionsLeft'] ?? null;
$available = $input['available'] ?? true;

if (($id !== null && !$id)
    || $name === ''
    || !preg_match('/^.{1,120}$/us', $name)
    || !$categoryId
    || (!is_string($price) && !is_int($price) && !is_float($price))
    || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', (string) $price)
    || (float) $price <= 0
    || (!is_bool($available))
    || ($portionsLeft !== null && (!is_int($portionsLeft) || $portionsLeft < 0 || $portionsLeft > 65535))) {
    json_response(['error' => 'Provide a valid name, category, price, portion count, and availability.'], 422);
}

$categoryCheck = $db->prepare('SELECT 1 FROM menu_categories WHERE category_id = ?');
$categoryCheck->execute([$categoryId]);
if (!$categoryCheck->fetchColumn()) {
    json_response(['error' => 'The selected category does not exist.'], 422);
}

if ($portionsLeft === 0) {
    $available = false;
}

if ($id === null) {
    $save = $db->prepare(
        'INSERT INTO menu_items (category_id, name, price, portions_left, is_available)
         VALUES (?, ?, ?, ?, ?)'
    );
    $save->execute([$categoryId, $name, $price, $portionsLeft, $available ? 1 : 0]);
    json_response(['id' => (int) $db->lastInsertId()], 201);
}

$save = $db->prepare(
    'UPDATE menu_items
     SET category_id = ?, name = ?, price = ?, portions_left = ?, is_available = ?
     WHERE menu_item_id = ? AND is_active = 1'
);
$save->execute([$categoryId, $name, $price, $portionsLeft, $available ? 1 : 0, $id]);
if ($save->rowCount() === 0) {
    $exists = $db->prepare('SELECT 1 FROM menu_items WHERE menu_item_id = ? AND is_active = 1');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) {
        json_response(['error' => 'Menu item was not found.'], 404);
    }
}

json_response(['id' => $id]);
