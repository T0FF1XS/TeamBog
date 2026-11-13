<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_response(['error' => 'Method not allowed.'], 405);
}

require_roles(['waiter', 'cashier', 'owner']);

$query = lanay_db()->query(
    "SELECT t.table_number, t.seats,
            CASE
                WHEN SUM(o.status IN ('new', 'preparing', 'ready')) > 0 THEN 'ordering'
                WHEN SUM(o.status = 'served') > 0 THEN 'eating'
                ELSE 'free'
            END AS status,
            COALESCE(MAX(CASE WHEN o.status IN ('new', 'preparing', 'ready', 'served')
                THEN o.guest_count END), 0) AS guests
     FROM dining_tables t
     LEFT JOIN orders o ON o.table_id = t.table_id
        AND o.status IN ('new', 'preparing', 'ready', 'served')
     GROUP BY t.table_id, t.table_number, t.seats
     ORDER BY t.table_number"
);
$tables = array_map(static function (array $table): array {
    return [
        'number' => (int) $table['table_number'],
        'seats' => (int) $table['seats'],
        'status' => $table['status'],
        'guests' => (int) $table['guests'],
    ];
}, $query->fetchAll());

json_response(['tables' => $tables]);
