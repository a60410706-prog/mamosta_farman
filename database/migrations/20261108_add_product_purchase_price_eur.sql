ALTER TABLE products
    ADD COLUMN purchase_price_eur DECIMAL(10, 2) NULL AFTER purchase_price_usd,
    MODIFY COLUMN purchase_price_usd DECIMAL(10, 2) NULL;
