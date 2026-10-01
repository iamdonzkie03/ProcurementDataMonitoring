# Procurement Data Monitoring System

PHP 8.2+, MySQL 8+, HTML5, CSS3, JavaScript (vanilla).

## Features
- Role-based access: Administrator, Editor, Viewer, Guest.
- Dashboard with PPMP/APP metrics.
- Area/Unit PPMP creation and maintenance.
- Automatic Consolidated APP: equivalent items are grouped by normalized item name + unit + category, regardless of area/unit.
- APP quantity is summed across all contributing PPMP records.
- APP unit price is a weighted average when source PPMP prices differ; total ABC is the sum of PPMP ABC values.
- Search/filter and export-friendly APP table.
- User administration for Administrator.
- PDO-style session authentication and password hashing.

## Setup
1. Create a MySQL database and import `database.sql`.
2. Copy the project into Apache's document root (e.g. `htdocs/procurement_monitoring`).
3. Edit `config/config.php` with your MySQL credentials.
4. Open `/public/login.php`.
5. Default administrator: `admin` / `Admin@123` (change immediately).

## Role permissions
- Administrator: all modules, users, PPMP create/edit/delete, APP, reports.
- Editor: dashboard, PPMP create/edit, APP, reports.
- Viewer: dashboard, PPMP view, APP, reports.
- Guest: dashboard and read-only APP/PPMP summary.

This is a production-oriented starter architecture. Add HTTPS, audit logging, backups, and server hardening before public deployment.
