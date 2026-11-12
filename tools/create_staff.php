<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/bootstrap.php';

$roles = ['waiter', 'kitchen', 'cashier', 'owner'];
$role = $argv[1] ?? '';
$name = trim($argv[2] ?? '');
if (!in_array($role, $roles, true) || $name === '' || strlen($name) > 100) {
    fwrite(STDERR, "Usage: php tools/create_staff.php <waiter|kitchen|cashier|owner> \"Display Name\"\n");
    exit(2);
}

fwrite(STDOUT, "Enter a 4-digit PIN: ");
$pin = trim((string) fgets(STDIN));
if (!preg_match('/^\d{4}$/', $pin)) {
    fwrite(STDERR, "PIN must contain exactly four digits.\n");
    exit(2);
}

$db = lanay_db();
$existingPins = $db->prepare('SELECT pin_hash FROM staff WHERE role = ? AND is_active = 1');
$existingPins->execute([$role]);
foreach ($existingPins->fetchAll(PDO::FETCH_COLUMN) as $existingHash) {
    if (password_verify($pin, $existingHash)) {
        fwrite(STDERR, "That PIN is already assigned to an active {$role} account.\n");
        exit(1);
    }
}

$insert = $db->prepare(
    'INSERT INTO staff (display_name, role, pin_hash) VALUES (?, ?, ?)'
);
$insert->execute([$name, $role, password_hash($pin, PASSWORD_DEFAULT)]);
fwrite(STDOUT, "Created {$role} account for {$name}.\n");
