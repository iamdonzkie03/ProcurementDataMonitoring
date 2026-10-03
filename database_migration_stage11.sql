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
