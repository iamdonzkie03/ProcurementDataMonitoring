USE procurement;

-- Enforce one PPMP per End-User / Implementing Unit for each Fiscal Year.
-- The Supervisor / Authorized Person is inherited from the End-User's Division/Department.
-- IMPORTANT: If duplicate PPMP rows already exist for the same fiscal_year + area_id,
-- reconcile those records first before running this migration.
SET @has_ppmp_unique := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='ppmp_items'
    AND INDEX_NAME='uq_ppmp_fiscal_year_area'
);
SET @sql := IF(@has_ppmp_unique=0,
  'ALTER TABLE ppmp_items ADD UNIQUE KEY uq_ppmp_fiscal_year_area (fiscal_year,area_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
