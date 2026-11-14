ALTER TABLE products
    ADD COLUMN damaged_quantity BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER stock_quantity;
