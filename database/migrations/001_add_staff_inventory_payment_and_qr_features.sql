ALTER TABLE dining_tables
    ADD COLUMN qr_token CHAR(64) NULL,
    ADD UNIQUE KEY uq_dining_tables_qr_token (qr_token);

UPDATE dining_tables
SET qr_token = SHA2(CONCAT(UUID(), RAND(), table_number), 256)
WHERE qr_token IS NULL;

ALTER TABLE orders
    ADD COLUMN source ENUM('staff', 'qr') NOT NULL DEFAULT 'staff',
    ADD COLUMN customer_tracking_token_hash CHAR(64) NULL,
    ADD UNIQUE KEY uq_orders_customer_tracking_token (customer_tracking_token_hash);

CREATE TABLE ingredients (
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

CREATE TABLE menu_item_ingredients (
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

CREATE TABLE inventory_movements (
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

CREATE TABLE payments (
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
