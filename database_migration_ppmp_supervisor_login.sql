USE procurement;

SET @has_ppmp_supervisor_enabled := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='divisions'
    AND COLUMN_NAME='ppmp_supervisor_enabled'
);
SET @sql := IF(@has_ppmp_supervisor_enabled=0,
  'ALTER TABLE divisions ADD COLUMN ppmp_supervisor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER electronic_signature',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;