CREATE DATABASE IF NOT EXISTS procurement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE procurement;

ALTER TABLE ppmp_items
  ADD COLUMN IF NOT EXISTS ppmp_no VARCHAR(80) NULL AFTER fiscal_year,
  ADD COLUMN IF NOT EXISTS procurement_type VARCHAR(80) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS procurement_mode VARCHAR(150) NULL AFTER unit,
  ADD COLUMN IF NOT EXISTS preprocurement_conference VARCHAR(20) NULL AFTER procurement_mode,
  ADD COLUMN IF NOT EXISTS start_procurement VARCHAR(20) NULL AFTER preprocurement_conference,
  ADD COLUMN IF NOT EXISTS end_procurement VARCHAR(20) NULL AFTER start_procurement,
  ADD COLUMN IF NOT EXISTS delivery_period VARCHAR(80) NULL AFTER end_procurement,
  ADD COLUMN IF NOT EXISTS source_of_funds VARCHAR(150) NULL AFTER delivery_period,
  ADD COLUMN IF NOT EXISTS supporting_documents TEXT NULL AFTER unit_price,
  ADD COLUMN IF NOT EXISTS prepared_by VARCHAR(150) NULL AFTER requested_by,
  ADD COLUMN IF NOT EXISTS prepared_position VARCHAR(150) NULL AFTER prepared_by,
  ADD COLUMN IF NOT EXISTS submitted_by VARCHAR(150) NULL AFTER prepared_position,
  ADD COLUMN IF NOT EXISTS submitted_position VARCHAR(150) NULL AFTER submitted_by,
  ADD COLUMN IF NOT EXISTS budget_approved_by VARCHAR(150) NULL AFTER submitted_position,
  ADD COLUMN IF NOT EXISTS budget_position VARCHAR(150) NULL AFTER budget_approved_by,
  ADD COLUMN IF NOT EXISTS prepared_date DATE NULL AFTER budget_position,
  ADD COLUMN IF NOT EXISTS submitted_date DATE NULL AFTER prepared_date,
  ADD COLUMN IF NOT EXISTS budget_date DATE NULL AFTER submitted_date;
