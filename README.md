# Procurement Data Monitoring System

PHP 8.2+, MySQL 8+, HTML5, CSS3, JavaScript (vanilla).

## Development Stage 2

The system now covers the planning-to-purchasing workflow:

- Role-based access: Administrator, Editor, Viewer, Guest.
- Dashboard with PPMP, APP, Purchase Request and Purchase Order metrics.
- Area/Unit PPMP creation.
- Automatic Consolidated APP: equivalent items are grouped by normalized item name + unit + category.
- Consolidated quantities and ABC are recalculated automatically.
- Purchase Request creation directly from available PPMP quantities.
- PR quantities automatically reduce the remaining PPMP quantity.
- Protection against requesting more than the remaining planned quantity.
- Purchase Request statuses: Draft, Submitted, Approved, Cancelled.
- Purchase Order creation linked to Submitted/Approved Purchase Requests.
- PO statuses: Draft, Issued, Cancelled.
- Procurement reports by Area/Unit plus PR/PO workflow status.
- Administrator user management.
- CSRF protection, password hashing and PDO prepared statements.

## Database upgrade

For a new installation, import `database.sql`.

For an existing Stage 1 installation, import:

`database_migration_stage2.sql`

Then update `config/config.php` with your MySQL credentials.

## Setup

1. Create the MySQL database and import the appropriate SQL file.
2. Copy the project into Apache's document root (for example `htdocs/procurement_monitoring`).
3. Edit `config/config.php`.
4. Open `/public/login.php`.
5. Default administrator: `admin` / `Admin@123`. Change this password immediately.

## Role permissions

- **Administrator:** full system access, user management, PPMP/APP/PR/PO and reports.
- **Editor:** create operational PPMP, PR and PO records; view APP and reports.
- **Viewer:** read-only PPMP, APP, PR, PO and reports.
- **Guest:** dashboard and read-only Consolidated APP.

## Procurement flow

`Area/Unit PPMP → Consolidated APP → Purchase Request → Purchase Order`

The PR module uses the original PPMP quantity as the planning baseline and subtracts non-cancelled PR quantities to determine the remaining available quantity.

PO preparation is linked to an existing Submitted/Approved PR and automatically carries its PR line items into the PO.

## Production considerations

Before public deployment, add HTTPS, audit logging, database backups, stronger password/session policies, approval controls, document numbering rules, attachment storage, and server hardening.
