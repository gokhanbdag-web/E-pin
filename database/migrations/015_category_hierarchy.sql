ALTER TABLE categories ADD COLUMN parent_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER id;
CREATE INDEX idx_categories_parent_id ON categories(parent_id);
