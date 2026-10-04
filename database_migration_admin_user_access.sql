USE procurement;

SET @has_division_id := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='division_id');
SET @sql := IF(@has_division_id=0,'ALTER TABLE users ADD COLUMN division_id INT UNSIGNED NULL AFTER status','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_area_id := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='area_id');
SET @sql := IF(@has_area_id=0,'ALTER TABLE users ADD COLUMN area_id INT UNSIGNED NULL AFTER division_id','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE INDEX idx_user_division ON users(division_id);
CREATE INDEX idx_user_area ON users(area_id);