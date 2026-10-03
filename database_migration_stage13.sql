USE procurement;

-- Change PPMP structure so one End-User/Fiscal Year can contain
-- multiple PPMP item records under the same PPMP number.
-- The application reuses the existing PPMP number when adding another item
-- for the same End-User and Fiscal Year.

SET @has_ppmp_unique := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='ppmp_items'
    AND INDEX_NAME='uq_ppmp_fiscal_year_area'
);

SET @sql := IF(@has_ppmp_unique>0,
  'ALTER TABLE ppmp_items DROP INDEX uq_ppmp_fiscal_year_area',
  'SELECT 1');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

