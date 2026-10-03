USE procurement;

-- Store the most recent PPMP save date/time.
SET @has_saved_at := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ppmp_items' AND COLUMN_NAME='saved_at'
);
SET @sql := IF(@has_saved_at=0,
  'ALTER TABLE ppmp_items ADD COLUMN saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_at',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Store the calculated PPMP total budget.
SET @has_total_budget := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ppmp_items' AND COLUMN_NAME='total_budget'
);
SET @sql := IF(@has_total_budget=0,
  'ALTER TABLE ppmp_items ADD COLUMN total_budget DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER unit_price',
  'SELECT 1');
PREPARE stmt2 FROM @sql;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
