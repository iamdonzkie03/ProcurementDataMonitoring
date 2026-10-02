CREATE DATABASE IF NOT EXISTS procurement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE procurement;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'areas'
    AND COLUMN_NAME = 'authorized_person'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE areas ADD COLUMN authorized_person VARCHAR(150) NULL AFTER code',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
