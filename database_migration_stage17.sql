USE procurement;

-- Electronic signatures belong to each individual name under an Area/Unit.
-- Existing Area/Unit-level signature column is no longer used.
SET @has_person_signature := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'area_personnel'
    AND COLUMN_NAME = 'electronic_signature'
);
SET @sql := IF(@has_person_signature = 0,
  'ALTER TABLE area_personnel ADD COLUMN electronic_signature VARCHAR(255) NULL AFTER position_designation',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_area_signature := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'areas'
    AND COLUMN_NAME = 'electronic_signature'
);
SET @sql := IF(@has_area_signature = 1,
  'ALTER TABLE areas DROP COLUMN electronic_signature',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
