<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inventory_helpers.php';

function inventory_note(mixed $value, string $fallback): ?string
{
    if ($value === null) {
        return $fallback;
    }
    if (!is_string($value) || !preg_match('/^.{0,255}$/us', $value)) {
        return null;
    }
    return trim($value) === '' ? $fallback : trim($value);
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(['error' => 'Method not allowed.'], 405);
}

$staff = require_roles(['owner']);
$db = lanay_db();

if ($method === 'GET') {
    $ingredients = $db->query(
        'SELECT ingredient_id, name, unit, stock_quantity, low_stock_threshold, is_active
         FROM ingredients
         ORDER BY is_active DESC, name'
    )->fetchAll();
    $recipes = $db->query(
        'SELECT r.menu_item_id, r.ingredient_id, r.quantity_per_portion,
                i.name AS ingredient_name, i.unit, i.is_active AS ingredient_active
         FROM menu_item_ingredients r
         JOIN ingredients i ON i.ingredient_id = r.ingredient_id
         ORDER BY r.menu_item_id, i.name'
    )->fetchAll();
    $menuItems = $db->query(
        'SELECT menu_item_id, name, is_active
         FROM menu_items
         ORDER BY is_active DESC, name'
    )->fetchAll();
    $movements = $db->query(
        'SELECT m.movement_id, m.ingredient_id, i.name AS ingredient_name,
                m.order_id, m.staff_id, s.display_name AS staff_name,
                m.movement_type, m.quantity_change, m.note, m.created_at
         FROM inventory_movements m
         JOIN ingredients i ON i.ingredient_id = m.ingredient_id
         LEFT JOIN staff s ON s.staff_id = m.staff_id
         ORDER BY m.movement_id DESC LIMIT 50'
    )->fetchAll();

    $recipeList = [];
    foreach ($recipes as $recipe) {
        $recipeList[(int) $recipe['menu_item_id']][] = [
            'ingredientId' => (int) $recipe['ingredient_id'],
            'name' => $recipe['ingredient_name'],
            'unit' => $recipe['unit'],
            'quantityPerPortion' => $recipe['quantity_per_portion'],
            'active' => (bool) $recipe['ingredient_active'],
        ];
    }

    json_response([
        'ingredients' => array_map(static function (array $ingredient): array {
            $stock = (float) $ingredient['stock_quantity'];
            $threshold = (float) $ingredient['low_stock_threshold'];
            return [
                'id' => (int) $ingredient['ingredient_id'],
                'name' => $ingredient['name'],
                'unit' => $ingredient['unit'],
                'stock' => $ingredient['stock_quantity'],
                'lowStockThreshold' => $ingredient['low_stock_threshold'],
                'lowStock' => (bool) $ingredient['is_active'] && $stock <= $threshold,
                'active' => (bool) $ingredient['is_active'],
            ];
        }, $ingredients),
        'menuItems' => array_map(static function (array $item) use ($recipeList): array {
            return [
                'id' => (int) $item['menu_item_id'],
                'name' => $item['name'],
                'active' => (bool) $item['is_active'],
                'recipe' => $recipeList[(int) $item['menu_item_id']] ?? [],
            ];
        }, $menuItems),
        'movements' => array_map(static function (array $movement): array {
            return [
                'id' => (int) $movement['movement_id'],
                'ingredientId' => (int) $movement['ingredient_id'],
                'ingredientName' => $movement['ingredient_name'],
                'orderId' => $movement['order_id'] === null ? null : (int) $movement['order_id'],
                'staffName' => $movement['staff_name'],
                'type' => $movement['movement_type'],
                'quantityChange' => $movement['quantity_change'],
                'note' => $movement['note'],
                'createdAt' => $movement['created_at'],
            ];
        }, $movements),
    ]);
}

require_csrf();
$input = request_json();
$action = $input['action'] ?? null;

if ($action === 'saveIngredient') {
    $name = $input['name'] ?? null;
    $unit = $input['unit'] ?? null;
    $threshold = inventory_quantity($input['lowStockThreshold'] ?? '0');
    $id = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : null;
    if (!is_string($name) || !is_string($unit) || !in_array($unit, ['g', 'ml', 'each'], true)
        || !preg_match('/^[^\r\n]{1,120}$/u', trim($name)) || $threshold === null
        || ($id !== null && !$id)) {
        json_response(['error' => 'Enter a valid ingredient name, unit, and low-stock threshold.'], 422);
    }
    $name = trim($name);

    if ($id === null) {
        $initialStock = inventory_quantity($input['initialStock'] ?? '0');
        if ($initialStock === null) {
            json_response(['error' => 'Enter a valid opening stock quantity.'], 422);
        }
        try {
            $db->beginTransaction();
            $insert = $db->prepare(
                'INSERT INTO ingredients (name, unit, stock_quantity, low_stock_threshold)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$name, $unit, $initialStock, $threshold]);
            $ingredientId = (int) $db->lastInsertId();
            if (inventory_milli($initialStock) > 0) {
                $movement = $db->prepare(
                    "INSERT INTO inventory_movements
                        (ingredient_id, staff_id, movement_type, quantity_change, note)
                     VALUES (?, ?, 'restock', ?, 'Opening stock')"
                );
                $movement->execute([$ingredientId, $staff['id'], $initialStock]);
            }
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($error->getCode() === '23000') {
                json_response(['error' => 'An ingredient with that name already exists.'], 409);
            }
            error_log('Lanay ingredient creation failed: ' . $error->getMessage());
            json_response(['error' => 'Ingredient could not be saved. Please try again.'], 500);
        }
        json_response(['id' => $ingredientId], 201);
    }

    $existing = $db->prepare(
        'SELECT unit FROM ingredients WHERE ingredient_id = ? AND is_active = 1'
    );
    $existing->execute([$id]);
    $currentUnit = $existing->fetchColumn();
    if ($currentUnit === false) {
        json_response(['error' => 'Ingredient was not found.'], 404);
    }
    if ($currentUnit !== $unit) {
        $recipeCount = $db->prepare(
            'SELECT COUNT(*) FROM menu_item_ingredients WHERE ingredient_id = ?'
        );
        $recipeCount->execute([$id]);
        if ((int) $recipeCount->fetchColumn() > 0) {
            json_response(['error' => 'Remove this ingredient from all recipes before changing its unit.'], 409);
        }
    }
    try {
        $update = $db->prepare(
            'UPDATE ingredients SET name = ?, unit = ?, low_stock_threshold = ?
             WHERE ingredient_id = ? AND is_active = 1'
        );
        $update->execute([$name, $unit, $threshold, $id]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            json_response(['error' => 'An ingredient with that name already exists.'], 409);
        }
        error_log('Lanay ingredient update failed: ' . $error->getMessage());
        json_response(['error' => 'Ingredient could not be saved. Please try again.'], 500);
    }
    json_response(['id' => (int) $id]);
}

if ($action === 'restock' || $action === 'adjust') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = inventory_quantity(
        $action === 'restock' ? ($input['quantity'] ?? null) : ($input['change'] ?? null),
        $action === 'adjust'
    );
    $note = inventory_note($input['note'] ?? null, $action === 'restock' ? 'Stock received' : 'Stock adjustment');
    if (!$id || $quantity === null || $note === null
        || ($action === 'restock' && inventory_milli($quantity) <= 0)
        || ($action === 'adjust' && inventory_milli($quantity) === 0)) {
        json_response(['error' => 'Enter a valid stock quantity and note.'], 422);
    }

    try {
        $db->beginTransaction();
        $query = $db->prepare(
            'SELECT stock_quantity FROM ingredients WHERE ingredient_id = ? AND is_active = 1 FOR UPDATE'
        );
        $query->execute([$id]);
        $currentStock = $query->fetchColumn();
        if ($currentStock === false) {
            $db->rollBack();
            json_response(['error' => 'Ingredient was not found.'], 404);
        }
        $newStockMilli = inventory_milli((string) $currentStock) + inventory_milli($quantity);
        if ($newStockMilli < 0) {
            $db->rollBack();
            json_response(['error' => 'The adjustment cannot reduce stock below zero.'], 422);
        }
        if ($newStockMilli > 999999999999) {
            $db->rollBack();
            json_response(['error' => 'Stock exceeds the supported limit.'], 422);
        }
        $db->prepare('UPDATE ingredients SET stock_quantity = ? WHERE ingredient_id = ?')
            ->execute([inventory_decimal($newStockMilli), $id]);
        $movementType = $action === 'restock' ? 'restock' : 'adjustment';
        $db->prepare(
            'INSERT INTO inventory_movements
                (ingredient_id, staff_id, movement_type, quantity_change, note)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $staff['id'], $movementType, $quantity, $note]);
        $db->commit();
    } catch (PDOException $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Lanay inventory movement failed: ' . $error->getMessage());
        json_response(['error' => 'Stock could not be updated. Please try again.'], 500);
    }
    json_response(['id' => (int) $id, 'stock' => inventory_decimal($newStockMilli)]);
}

if ($action === 'saveRecipe') {
    $menuItemId = filter_var($input['menuItemId'] ?? null, FILTER_VALIDATE_INT);
    $recipe = $input['ingredients'] ?? null;
    if (!$menuItemId || !is_array($recipe) || count($recipe) > 30) {
        json_response(['error' => 'Select a menu item and provide a valid recipe.'], 422);
    }

    $quantities = [];
    foreach ($recipe as $line) {
        if (!is_array($line)) {
            json_response(['error' => 'Each recipe line must include an ingredient and quantity.'], 422);
        }
        $ingredientId = filter_var($line['ingredientId'] ?? null, FILTER_VALIDATE_INT);
        $quantity = inventory_quantity($line['quantityPerPortion'] ?? null);
        if (!$ingredientId || $quantity === null || inventory_milli($quantity) <= 0
            || isset($quantities[$ingredientId])) {
            json_response(['error' => 'Each recipe ingredient must be unique and have a positive quantity.'], 422);
        }
        $quantities[$ingredientId] = $quantity;
    }

    try {
        $db->beginTransaction();
        $menuCheck = $db->prepare(
            'SELECT 1 FROM menu_items WHERE menu_item_id = ? AND is_active = 1 FOR UPDATE'
        );
        $menuCheck->execute([$menuItemId]);
        if (!$menuCheck->fetchColumn()) {
            $db->rollBack();
            json_response(['error' => 'Active menu item was not found.'], 404);
        }
        if ($quantities) {
            $ids = array_keys($quantities);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $ingredientCheck = $db->prepare(
                "SELECT ingredient_id FROM ingredients
                 WHERE is_active = 1 AND ingredient_id IN ({$placeholders})
                 FOR UPDATE"
            );
            $ingredientCheck->execute($ids);
            if (count($ingredientCheck->fetchAll(PDO::FETCH_COLUMN)) !== count($quantities)) {
                $db->rollBack();
                json_response(['error' => 'One or more selected ingredients are inactive or missing.'], 422);
            }
        }
        $db->prepare('DELETE FROM menu_item_ingredients WHERE menu_item_id = ?')
            ->execute([$menuItemId]);
        $insertRecipe = $db->prepare(
            'INSERT INTO menu_item_ingredients
                (menu_item_id, ingredient_id, quantity_per_portion)
             VALUES (?, ?, ?)'
        );
        foreach ($quantities as $ingredientId => $quantity) {
            $insertRecipe->execute([$menuItemId, $ingredientId, $quantity]);
        }
        $db->commit();
    } catch (PDOException $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Lanay recipe update failed: ' . $error->getMessage());
        json_response(['error' => 'Recipe could not be saved. Please try again.'], 500);
    }
    json_response(['menuItemId' => (int) $menuItemId]);
}

if ($action === 'archiveIngredient') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(['error' => 'A valid ingredient ID is required.'], 422);
    }
    $query = $db->prepare(
        'SELECT 1 FROM ingredients WHERE ingredient_id = ? AND is_active = 1'
    );
    $query->execute([$id]);
    if (!$query->fetchColumn()) {
        json_response(['error' => 'Ingredient was not found.'], 404);
    }
    $db->prepare('UPDATE ingredients SET is_active = 0 WHERE ingredient_id = ?')->execute([$id]);
    json_response(['id' => (int) $id, 'active' => false]);
}

json_response(['error' => 'Unknown inventory action.'], 422);
