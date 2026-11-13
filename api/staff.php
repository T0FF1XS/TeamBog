<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function staff_pin_is_assigned(PDO $db, string $role, string $pin, ?int $excludeId = null): bool
{
    $sql = 'SELECT pin_hash FROM staff WHERE role = ? AND is_active = 1';
    $parameters = [$role];
    if ($excludeId !== null) {
        $sql .= ' AND staff_id <> ?';
        $parameters[] = $excludeId;
    }

    $existingPins = $db->prepare($sql);
    $existingPins->execute($parameters);
    foreach ($existingPins->fetchAll(PDO::FETCH_COLUMN) as $existingHash) {
        if (password_verify($pin, $existingHash)) {
            return true;
        }
    }
    return false;
}

function active_owner_ids(PDO $db): array
{
    return $db->query(
        "SELECT staff_id FROM staff WHERE role = 'owner' AND is_active = 1 FOR UPDATE"
    )->fetchAll(PDO::FETCH_COLUMN);
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(['error' => 'Method not allowed.'], 405);
}

$signedInStaff = require_roles(['owner']);

if ($method === 'GET') {
    $query = lanay_db()->query(
        'SELECT staff_id, display_name, role, is_active, created_at
         FROM staff
         ORDER BY created_at DESC, staff_id DESC'
    );
    $staff = array_map(static function (array $account): array {
        return [
            'id' => (int) $account['staff_id'],
            'name' => $account['display_name'],
            'role' => $account['role'],
            'active' => (bool) $account['is_active'],
            'createdAt' => $account['created_at'],
        ];
    }, $query->fetchAll());

    json_response(['staff' => $staff]);
}

require_csrf();
$input = request_json();
$action = $input['action'] ?? 'create';
$roles = ['waiter', 'kitchen', 'cashier', 'owner'];
$db = lanay_db();

if ($action === 'deactivate' || $action === 'activate') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(['error' => 'A valid staff account is required.'], 422);
    }

    if ($action === 'deactivate' && $id === $signedInStaff['id']) {
        json_response(['error' => 'Sign in with another owner before deactivating this account.'], 409);
    }

    try {
        $db->beginTransaction();
        $find = $db->prepare(
            'SELECT role, is_active FROM staff WHERE staff_id = ? FOR UPDATE'
        );
        $find->execute([$id]);
        $account = $find->fetch();
        if (!$account) {
            $db->rollBack();
            json_response(['error' => 'Staff account was not found.'], 404);
        }
        if ($action === 'deactivate') {
            if (!(bool) $account['is_active']) {
                $db->rollBack();
                json_response(['error' => 'This account is already inactive.'], 409);
            }
            if ($account['role'] === 'owner' && count(active_owner_ids($db)) <= 1) {
                $db->rollBack();
                json_response(['error' => 'At least one active owner account must remain.'], 409);
            }
            $db->prepare('UPDATE staff SET is_active = 0 WHERE staff_id = ?')->execute([$id]);
        } else {
            $pin = $input['pin'] ?? null;
            if (!(bool) $account['is_active']) {
                if (!is_string($pin) || !preg_match('/^[0-9]{4}$/D', $pin)) {
                    $db->rollBack();
                    json_response(['error' => 'Enter a new 4-digit PIN to reactivate this account.'], 422);
                }
                if (staff_pin_is_assigned($db, $account['role'], $pin, $id)) {
                    $db->rollBack();
                    json_response(['error' => "That PIN is already assigned to an active {$account['role']} account."], 409);
                }
                $db->prepare(
                    'UPDATE staff SET pin_hash = ?, is_active = 1 WHERE staff_id = ?'
                )->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);
            } else {
                $db->rollBack();
                json_response(['error' => 'This account is already active.'], 409);
            }
        }
        $db->commit();
    } catch (PDOException $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Lanay staff status update failed: ' . $error->getMessage());
        json_response(['error' => 'Staff account could not be updated. Please try again.'], 500);
    }

    json_response(['id' => (int) $id, 'active' => $action === 'activate']);
}

if ($action !== 'create' && $action !== 'update') {
    json_response(['error' => 'Unknown staff action.'], 422);
}

$name = $input['name'] ?? null;
$role = $input['role'] ?? null;
$pin = $input['pin'] ?? '';
if (!is_string($name) || !is_string($role) || !is_string($pin)) {
    json_response(['error' => 'Enter a name and role, and provide a 4-digit PIN when needed.'], 422);
}

$name = trim($name);
if (!preg_match('/^[^\r\n]{1,100}$/u', $name) || !in_array($role, $roles, true)) {
    json_response(['error' => 'Enter a valid name and role.'], 422);
}

if ($action === 'create') {
    if (!preg_match('/^[0-9]{4}$/D', $pin)) {
        json_response(['error' => 'Enter a valid 4-digit PIN.'], 422);
    }
    if (staff_pin_is_assigned($db, $role, $pin)) {
        json_response(['error' => "That PIN is already assigned to an active {$role} account."], 409);
    }

    $insert = $db->prepare(
        'INSERT INTO staff (display_name, role, pin_hash) VALUES (?, ?, ?)'
    );
    $insert->execute([$name, $role, password_hash($pin, PASSWORD_DEFAULT)]);
    json_response(['id' => (int) $db->lastInsertId()], 201);
}

$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) {
    json_response(['error' => 'A valid staff account is required.'], 422);
}
$accountQuery = $db->prepare(
    'SELECT role, is_active FROM staff WHERE staff_id = ?'
);
$accountQuery->execute([$id]);
$account = $accountQuery->fetch();
if (!$account) {
    json_response(['error' => 'Staff account was not found.'], 404);
}

$roleChanged = $account['role'] !== $role;
if ($roleChanged && $id === $signedInStaff['id']) {
    json_response(['error' => 'Sign in with another owner before changing your own role.'], 409);
}
if ($roleChanged && $pin === '') {
    json_response(['error' => 'Enter a new 4-digit PIN when changing an account role.'], 422);
}
if ($pin !== '' && !preg_match('/^[0-9]{4}$/D', $pin)) {
    json_response(['error' => 'Enter a valid 4-digit PIN.'], 422);
}

$db->beginTransaction();
$lockedQuery = $db->prepare(
    'SELECT role, is_active FROM staff WHERE staff_id = ? FOR UPDATE'
);
$lockedQuery->execute([$id]);
$lockedAccount = $lockedQuery->fetch();
if (!$lockedAccount) {
    $db->rollBack();
    json_response(['error' => 'Staff account was not found.'], 404);
}
if ($lockedAccount['role'] === 'owner' && (bool) $lockedAccount['is_active']
    && $role !== 'owner' && count(active_owner_ids($db)) <= 1) {
    $db->rollBack();
    json_response(['error' => 'At least one active owner account must remain.'], 409);
}
if ($pin !== '' && staff_pin_is_assigned($db, $role, $pin, $id)) {
    $db->rollBack();
    json_response(['error' => "That PIN is already assigned to an active {$role} account."], 409);
}

if ($pin === '') {
    $update = $db->prepare(
        'UPDATE staff SET display_name = ?, role = ? WHERE staff_id = ?'
    );
    $update->execute([$name, $role, $id]);
} else {
    $update = $db->prepare(
        'UPDATE staff SET display_name = ?, role = ?, pin_hash = ? WHERE staff_id = ?'
    );
    $update->execute([$name, $role, password_hash($pin, PASSWORD_DEFAULT), $id]);
}
$db->commit();

json_response(['id' => (int) $id, 'active' => (bool) $lockedAccount['is_active']]);
