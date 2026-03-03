# MSPDashboard (Phase 1)

Clean build for a cPanel-hosted helpdesk dashboard.

## Stack
- PHP 8.x
- MySQL
- Vanilla JS frontend (Phase 1 only includes plumbing)

## cPanel deployment (subdomain)
1. Create subdomain in cPanel.
2. Set subdomain document root to this project folder's `/public` directory.
3. Ensure project root (sibling of `/public`) is writable by PHP for installer-generated `_config/`.
4. Confirm PHP extensions: `pdo_mysql`, `curl`, `json`, and either `sodium` or `openssl`.

## Directory layout
- `/public` is webroot.
- `/_config` is outside webroot and created by installer:
  - `config.php`
  - `app.key`
  - `installed.lock`

## Install steps
1. Browse to `/install/`.
2. Complete wizard:
   - Requirements check
   - DB setup (writes `_config/config.php`)
   - Run migration `database/migrations/001_init.sql`
   - Create admin user
   - Optional IP allowlist + intervals
   - Finish (writes `_config/app.key` and `_config/installed.lock`)
3. Login at `/admin/login.php`.
4. Manage Phase 1 settings at `/admin/settings.php`.

## Re-run installer
Delete:
- `/_config/installed.lock`

Then browse to `/install/` again.
