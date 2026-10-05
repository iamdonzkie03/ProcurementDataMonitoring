-- PPMP Masterlist table migration
-- Run this migration on existing procurement databases.

CREATE TABLE IF NOT EXISTS ppmp_masterlist (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_name VARCHAR(255) NOT NULL,
  technical_specifications TEXT NULL,
  unit_of_measurement VARCHAR(100) NOT NULL,
  unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ppmp_masterlist_item (item_name),
  INDEX idx_ppmp_masterlist_uom (unit_of_measurement)
) ENGINE=InnoDB;
