<?php
declare(strict_types=1);

function fetch_menu_items(PDO $db, bool $includeArchived = false): array
{
    $where = $includeArchived ? '' : 'WHERE i.is_active = 1';
    $query = $db->query(
        "SELECT i.menu_item_id, i.category_id, c.name AS category_name,
                i.name, i.price, i.portions_left, i.is_available, i.is_active,
                NOT EXISTS (
                    SELECT 1
                    FROM menu_item_ingredients r
                    JOIN ingredients stock ON stock.ingredient_id = r.ingredient_id
                    WHERE r.menu_item_id = i.menu_item_id
                      AND (stock.is_active = 0 OR stock.stock_quantity < r.quantity_per_portion)
                ) AS ingredients_available
         FROM menu_items i
         JOIN menu_categories c ON c.category_id = i.category_id
         {$where}
         ORDER BY c.category_id, i.name"
    );

    return array_map(static function (array $item): array {
        $inventoryAvailable = (bool) $item['ingredients_available'];
        return [
            'id' => (int) $item['menu_item_id'],
            'categoryId' => (int) $item['category_id'],
            'category' => $item['category_name'],
            'name' => $item['name'],
            'price' => $item['price'],
            'portionsLeft' => $item['portions_left'] === null ? null : (int) $item['portions_left'],
            'available' => (bool) $item['is_available']
                && ($item['portions_left'] === null || (int) $item['portions_left'] > 0)
                && $inventoryAvailable,
            'inventoryAvailable' => $inventoryAvailable,
            'active' => (bool) $item['is_active'],
        ];
    }, $query->fetchAll());
}
