CREATE DATABASE IF NOT EXISTS lanay_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE lanay_db;

CREATE TABLE IF NOT EXISTS staff (
    staff_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(100) NOT NULL,
    role ENUM('waiter', 'kitchen', 'cashier', 'owner') NOT NULL,
    pin_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (staff_id),
    KEY idx_staff_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address VARCHAR(45) NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    PRIMARY KEY (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_categories (
    category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_menu_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
    menu_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    price DECIMAL(10, 2) UNSIGNED NOT NULL,
    portions_left INT UNSIGNED NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (menu_item_id),
    KEY idx_menu_items_category_active (category_id, is_active),
    CONSTRAINT fk_menu_items_category
        FOREIGN KEY (category_id) REFERENCES menu_categories (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dining_tables (
    table_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_number SMALLINT UNSIGNED NOT NULL,
    seats TINYINT UNSIGNED NOT NULL DEFAULT 4,
    qr_token CHAR(64) NULL,
    status ENUM('free', 'ordering', 'eating') NOT NULL DEFAULT 'free',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (table_id),
    UNIQUE KEY uq_dining_tables_number (table_number),
    UNIQUE KEY uq_dining_tables_qr_token (qr_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_id INT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    source ENUM('staff', 'qr') NOT NULL DEFAULT 'staff',
    customer_tracking_token_hash CHAR(64) NULL,
    guest_count TINYINT UNSIGNED NOT NULL,
    status ENUM('new', 'preparing', 'ready', 'served', 'paid', 'cancelled')
        NOT NULL DEFAULT 'new',
    kitchen_note VARCHAR(500) NOT NULL DEFAULT '',
    total DECIMAL(10, 2) UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    KEY idx_orders_status_created (status, created_at),
    KEY idx_orders_table_status (table_id, status),
    UNIQUE KEY uq_orders_customer_tracking_token (customer_tracking_token_hash),
    CONSTRAINT fk_orders_table
        FOREIGN KEY (table_id) REFERENCES dining_tables (table_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_orders_staff
        FOREIGN KEY (created_by) REFERENCES staff (staff_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NULL,
    item_name VARCHAR(120) NOT NULL,
    unit_price DECIMAL(10, 2) UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (order_item_id),
    KEY idx_order_items_order (order_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders (order_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_order_items_menu_item
        FOREIGN KEY (menu_item_id) REFERENCES menu_items (menu_item_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingredients (
    ingredient_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    unit ENUM('g', 'ml', 'each') NOT NULL,
    stock_quantity DECIMAL(12, 3) UNSIGNED NOT NULL DEFAULT 0,
    low_stock_threshold DECIMAL(12, 3) UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ingredient_id),
    UNIQUE KEY uq_ingredients_name (name),
    KEY idx_ingredients_active_stock (is_active, stock_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_item_ingredients (
    menu_item_id BIGINT UNSIGNED NOT NULL,
    ingredient_id BIGINT UNSIGNED NOT NULL,
    quantity_per_portion DECIMAL(12, 3) UNSIGNED NOT NULL,
    PRIMARY KEY (menu_item_id, ingredient_id),
    CONSTRAINT fk_recipe_menu_item
        FOREIGN KEY (menu_item_id) REFERENCES menu_items (menu_item_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_recipe_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
    movement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingredient_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NULL,
    movement_type ENUM('restock', 'adjustment', 'order') NOT NULL,
    quantity_change DECIMAL(12, 3) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (movement_id),
    UNIQUE KEY uq_inventory_order_movement (order_id, ingredient_id, movement_type),
    KEY idx_inventory_movements_ingredient_created (ingredient_id, created_at),
    CONSTRAINT fk_inventory_movement_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_movement_order
        FOREIGN KEY (order_id) REFERENCES orders (order_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_inventory_movement_staff
        FOREIGN KEY (staff_id) REFERENCES staff (staff_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    received_by BIGINT UNSIGNED NULL,
    method ENUM('cash', 'gcash', 'card') NOT NULL,
    amount DECIMAL(10, 2) UNSIGNED NOT NULL,
    amount_received DECIMAL(10, 2) UNSIGNED NOT NULL,
    change_given DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0,
    reference VARCHAR(120) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payments_order (order_id),
    KEY idx_payments_created (created_at),
    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id) REFERENCES orders (order_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payments_staff
        FOREIGN KEY (received_by) REFERENCES staff (staff_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO menu_categories (category_id, name) VALUES
    (1, 'Sushi'),
    (2, 'Japanese'),
    (3, 'Korean'),
    (4, 'Italian'),
    (5, 'Filipino'),
    (6, 'Drinks');

INSERT IGNORE INTO menu_items
    (menu_item_id, category_id, name, price, portions_left, is_available)
VALUES
    (1, 1, 'Salmon nigiri', 120.00, 20, 1),
    (2, 1, 'California maki', 180.00, NULL, 1),
    (3, 2, 'Chicken katsu curry', 210.00, NULL, 1),
    (4, 2, 'Gyoza', 95.00, NULL, 1),
    (5, 2, 'Tonkotsu ramen', 260.00, 0, 0),
    (6, 6, 'Iced matcha', 85.00, NULL, 1);

INSERT IGNORE INTO dining_tables (table_number, seats) VALUES
    (1, 4), (2, 4), (3, 4), (4, 4), (5, 4),
    (6, 4), (7, 4), (8, 4), (9, 4), (10, 4);

UPDATE dining_tables
SET qr_token = SHA2(CONCAT(UUID(), RAND(), table_number), 256)
WHERE qr_token IS NULL;
