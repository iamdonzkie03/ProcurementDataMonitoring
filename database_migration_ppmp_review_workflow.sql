USE procurement;

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