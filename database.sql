CREATE DATABASE IF NOT EXISTS procurement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE procurement;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('Administrator','Editor','Viewer','Guest') NOT NULL DEFAULT 'Guest',
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS divisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  division_head VARCHAR(150) NOT NULL,
  head_position_designation VARCHAR(150) NULL,
  electronic_signature VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS areas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  division_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL UNIQUE,
  code VARCHAR(50) NULL UNIQUE,
  electronic_signature VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_area_division FOREIGN KEY(division_id) REFERENCES divisions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS area_personnel (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  area_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  position_designation VARCHAR(150) NULL,
  electronic_signature VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_area_personnel_area FOREIGN KEY(area_id) REFERENCES areas(id) ON DELETE CASCADE,
  UNIQUE KEY uq_area_personnel_name (area_id,name),
  INDEX idx_area_personnel_area (area_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ppmp_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year YEAR NOT NULL,
  ppmp_no VARCHAR(80) NULL,
  area_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  procurement_type VARCHAR(80) NULL,
  quantity DECIMAL(18,4) NOT NULL,
  unit VARCHAR(50) NOT NULL,
  procurement_mode VARCHAR(150) NULL,
  preprocurement_conference VARCHAR(20) NULL,
  start_procurement VARCHAR(20) NULL,
  end_procurement VARCHAR(20) NULL,
  delivery_period VARCHAR(80) NULL,
  source_of_funds VARCHAR(150) NULL,
  unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_budget DECIMAL(18,2) NOT NULL DEFAULT 0,
  supporting_documents TEXT NULL,
  requested_by VARCHAR(150) NULL,
  prepared_by VARCHAR(150) NULL,
  prepared_position VARCHAR(150) NULL,
  submitted_by VARCHAR(150) NULL,
  submitted_position VARCHAR(150) NULL,
  budget_approved_by VARCHAR(150) NULL,
  budget_position VARCHAR(150) NULL,
  prepared_date DATE NULL,
  submitted_date DATE NULL,
  budget_date DATE NULL,
  remarks TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ppmp_area FOREIGN KEY(area_id) REFERENCES areas(id),
  CONSTRAINT fk_ppmp_category FOREIGN KEY(category_id) REFERENCES categories(id),
  CONSTRAINT fk_ppmp_user FOREIGN KEY(created_by) REFERENCES users(id),
  INDEX idx_ppmp_year (fiscal_year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ppmp_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year YEAR NOT NULL,
  area_id INT UNSIGNED NOT NULL,
  ppmp_no VARCHAR(80) NOT NULL,
  status ENUM('Draft','Pending for Review','Pending for Approval','Approved','Declined') NOT NULL DEFAULT 'Draft',
  submitted_by INT UNSIGNED NULL,
  submitted_at DATETIME NULL,
  supervisor_reviewed_by INT UNSIGNED NULL,
  supervisor_reviewed_at DATETIME NULL,
  budget_reviewed_by INT UNSIGNED NULL,
  budget_reviewed_at DATETIME NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ppmp_review_area FOREIGN KEY(area_id) REFERENCES areas(id),
  CONSTRAINT fk_ppmp_review_submitter FOREIGN KEY(submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ppmp_review_supervisor FOREIGN KEY(supervisor_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ppmp_review_budget FOREIGN KEY(budget_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_ppmp_review (fiscal_year, area_id, ppmp_no),
  INDEX idx_ppmp_review_status (status),
  INDEX idx_ppmp_review_area (area_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pr_no VARCHAR(60) NOT NULL UNIQUE,
  fiscal_year YEAR NOT NULL,
  area_id INT UNSIGNED NOT NULL,
  purpose TEXT NULL,
  status ENUM('Draft','Submitted','Approved','Cancelled') NOT NULL DEFAULT 'Draft',
  requested_by VARCHAR(150) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(area_id) REFERENCES areas(id),
  FOREIGN KEY(created_by) REFERENCES users(id),
  INDEX idx_pr_year (fiscal_year),
  INDEX idx_pr_area (area_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS procurement_methods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  procurement_method VARCHAR(150) NOT NULL UNIQUE,
  details TEXT NULL,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS units_of_measure (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;

INSERT INTO units_of_measure(name) VALUES ('Unit'),('Piece'),('Lot'),('Vial'),('Box'),('Pack'),('Set'),('Bottle'),('Can'),('Roll'),('Ream'),('Meter'),('Kilogram'),('Liter') ON DUPLICATE KEY UPDATE name=VALUES(name);

CREATE TABLE IF NOT EXISTS purchase_request_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pr_id BIGINT UNSIGNED NOT NULL,
  ppmp_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(18,4) NOT NULL,
  unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
  unit_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(pr_id) REFERENCES purchase_requests(id) ON DELETE CASCADE,
  FOREIGN KEY(ppmp_item_id) REFERENCES ppmp_items(id),
  FOREIGN KEY(unit_id) REFERENCES units_of_measure(id),
  UNIQUE KEY uq_pr_ppmp (pr_id, ppmp_item_id),
  INDEX idx_pri_ppmp (ppmp_item_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_no VARCHAR(60) NOT NULL UNIQUE,
  pr_id BIGINT UNSIGNED NOT NULL,
  supplier VARCHAR(255) NOT NULL,
  po_date DATE NULL,
  status ENUM('Draft','Issued','Cancelled') NOT NULL DEFAULT 'Draft',
  remarks TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(pr_id) REFERENCES purchase_requests(id),
  FOREIGN KEY(created_by) REFERENCES users(id),
  INDEX idx_po_pr (pr_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id BIGINT UNSIGNED NOT NULL,
  pr_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(18,4) NOT NULL,
  unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY(pr_item_id) REFERENCES purchase_request_items(id),
  UNIQUE KEY uq_po_pritem (po_id, pr_item_id)
) ENGINE=InnoDB;

INSERT INTO divisions(name,division_head) VALUES
('Administration','Not Yet Assigned'),
('Finance','Not Yet Assigned'),
('Human Resource','Not Yet Assigned'),
('Information Technology','Not Yet Assigned')
ON DUPLICATE KEY UPDATE name=VALUES(name), division_head=division_head;
INSERT INTO areas(division_id,name,code)
SELECT d.id,'Administration','ADMIN' FROM divisions d WHERE d.name='Administration' AND NOT EXISTS (SELECT 1 FROM areas a WHERE a.name='Administration')
UNION ALL SELECT d.id,'Finance','FIN' FROM divisions d WHERE d.name='Finance' AND NOT EXISTS (SELECT 1 FROM areas a WHERE a.name='Finance')
UNION ALL SELECT d.id,'Human Resource','HR' FROM divisions d WHERE d.name='Human Resource' AND NOT EXISTS (SELECT 1 FROM areas a WHERE a.name='Human Resource')
UNION ALL SELECT d.id,'Information Technology','IT' FROM divisions d WHERE d.name='Information Technology' AND NOT EXISTS (SELECT 1 FROM areas a WHERE a.name='Information Technology');
INSERT INTO categories(name) VALUES ('Office Supplies'),('IT Equipment'),('Furniture'),('Infrastructure'),('Professional Services') ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO users(username,full_name,email,password_hash,role,status) VALUES
('admin','System Administrator','admin@example.local','$2y$12$xauFrgrusgURr3xHolB7KeOrO7zCyWm.RqoGXz5VjFrZ0csA6Lxca','Administrator','Active')
ON DUPLICATE KEY UPDATE username=VALUES(username);
