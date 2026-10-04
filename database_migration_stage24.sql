USE procurement;

CREATE TABLE IF NOT EXISTS ppmp_review_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  review_id BIGINT UNSIGNED NOT NULL,
  ppmp_item_id BIGINT UNSIGNED NOT NULL,
  status ENUM('Pending for Review','Approved','Declined','Pending for Approval','Budget Approved','Budget Declined') NOT NULL DEFAULT 'Pending for Review',
  supervisor_remarks TEXT NULL,
  supervisor_reviewed_by INT UNSIGNED NULL,
  supervisor_reviewed_at DATETIME NULL,
  budget_remarks TEXT NULL,
  budget_reviewed_by INT UNSIGNED NULL,
  budget_reviewed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pri_review FOREIGN KEY(review_id) REFERENCES ppmp_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_pri_item FOREIGN KEY(ppmp_item_id) REFERENCES ppmp_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_pri_supervisor FOREIGN KEY(supervisor_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pri_budget FOREIGN KEY(budget_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_pri_review_item (review_id, ppmp_item_id),
  INDEX idx_pri_review_status (status)
) ENGINE=InnoDB;
