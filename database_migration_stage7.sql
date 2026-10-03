USE procurement;

-- Ensure the Area/Unit names table exists in existing XAMPP databases.
CREATE TABLE IF NOT EXISTS area_personnel (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  area_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_area_personnel_area FOREIGN KEY(area_id) REFERENCES areas(id) ON DELETE CASCADE,
  UNIQUE KEY uq_area_personnel_name (area_id,name),
  INDEX idx_area_personnel_area (area_id)
) ENGINE=InnoDB;
