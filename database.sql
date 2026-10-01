CREATE DATABASE IF NOT EXISTS procurement_monitoring CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE procurement_monitoring;

CREATE TABLE users (
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

CREATE TABLE areas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  code VARCHAR(50) NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE ppmp_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year YEAR NOT NULL,
  area_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  quantity DECIMAL(18,4) NOT NULL,
  unit VARCHAR(50) NOT NULL,
  unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
  requested_by VARCHAR(150) NULL,
  remarks TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ppmp_area FOREIGN KEY(area_id) REFERENCES areas(id),
  CONSTRAINT fk_ppmp_category FOREIGN KEY(category_id) REFERENCES categories(id),
  CONSTRAINT fk_ppmp_user FOREIGN KEY(created_by) REFERENCES users(id),
  INDEX idx_ppmp_year (fiscal_year),
  INDEX idx_ppmp_consolidation (fiscal_year, area_id, category_id, unit, item_name)
) ENGINE=InnoDB;

INSERT INTO areas(name,code) VALUES ('Administration','ADMIN'),('Finance','FIN'),('Human Resource','HR'),('Information Technology','IT') ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO categories(name) VALUES ('Office Supplies'),('IT Equipment'),('Furniture'),('Infrastructure'),('Professional Services') ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO users(username,full_name,email,password_hash,role) VALUES
('admin','System Administrator','admin@example.local','$2y$12$xauFrgrusgURr3xHolB7KeOrO7zCyWm.RqoGXz5VjFrZ0csA6Lxca','Administrator');
