CREATE DATABASE IF NOT EXISTS inventory_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE inventory_db;

CREATE TABLE IF NOT EXISTS categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_logs (
    log_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(20) NOT NULL,
    action_description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS price_history (
    history_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL,
    old_price DECIMAL(10, 2) NOT NULL,
    new_price DECIMAL(10, 2) NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS deleted_products (
    archive_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    category_name VARCHAR(100) NULL,
    price DECIMAL(10, 2) NOT NULL,
    stock_quantity INT UNSIGNED NOT NULL,
    original_created_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_orders (
    order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NULL,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_email VARCHAR(254) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    order_status ENUM('pending', 'paid', 'cancelled', 'failed') NOT NULL DEFAULT 'pending',
    stock_reserved TINYINT(1) NOT NULL DEFAULT 1,
    checkout_session_id VARCHAR(255) NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    CONSTRAINT fk_customer_orders_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_loans (
    loan_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NULL,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_email VARCHAR(254) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    loan_status ENUM('active', 'returned') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    returned_at TIMESTAMP NULL,
    CONSTRAINT fk_customer_loans_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    event_id VARCHAR(255) PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (category_name, description) VALUES
    ('Electronics', 'Electronic devices and computer accessories'),
    ('Office Supplies', 'School and office materials'),
    ('Accessories', 'Other useful accessories');

DROP TRIGGER IF EXISTS trg_products_after_insert;
DROP TRIGGER IF EXISTS trg_products_after_update;
DROP TRIGGER IF EXISTS trg_products_before_delete;

DELIMITER $$

CREATE TRIGGER trg_products_after_insert
AFTER INSERT ON products
FOR EACH ROW
BEGIN
    INSERT INTO activity_logs
        (product_id, action_type, action_description)
    VALUES (
        NEW.product_id,
        'INSERT',
        CONCAT(
            'Product added: ',
            NEW.product_name,
            ' (SKU: ',
            NEW.sku,
            ')'
        )
    );
END$$

CREATE TRIGGER trg_products_after_update
AFTER UPDATE ON products
FOR EACH ROW
BEGIN
    IF NOT (OLD.price <=> NEW.price) THEN
        INSERT INTO price_history
            (product_id, product_name, sku, old_price, new_price)
        VALUES (
            OLD.product_id,
            NEW.product_name,
            NEW.sku,
            OLD.price,
            NEW.price
        );
    END IF;
END$$

CREATE TRIGGER trg_products_before_delete
BEFORE DELETE ON products
FOR EACH ROW
BEGIN
    INSERT INTO deleted_products (
        original_product_id,
        product_name,
        sku,
        description,
        category_id,
        category_name,
        price,
        stock_quantity,
        original_created_at
    )
    VALUES (
        OLD.product_id,
        OLD.product_name,
        OLD.sku,
        OLD.description,
        OLD.category_id,
        (
            SELECT category_name
            FROM categories
            WHERE category_id = OLD.category_id
        ),
        OLD.price,
        OLD.stock_quantity,
        OLD.created_at
    );
END$$

DELIMITER ;
