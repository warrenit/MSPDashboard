# MSPDashboard (Phase 2)

Clean build for a cPanel-hosted helpdesk dashboard.

## Stack
- PHP 8.x
- MySQL
- Vanilla JS frontend (polling `/api/dashboard.php` without full page reload)

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
4. Configure settings at `/admin/settings.php`.

## Phase 2 features
- TV-friendly dashboard at `/` with:
  - header (logo placeholder, date/time, last updated)
  - API status strip (Halo/Datto/Kuma/RSS)
  - optional RSS ticker row
  - 12 tiles including Helpdesk Health
  - two CSS/HTML bar charts (no JS chart library)
  - Kuma exceptions list panel
- Frontend polling interval uses `refresh_interval_sec` setting.
- `/api/dashboard.php` returns complete schema with mock/default data until integrations are added.

## Re-run installer
Delete:
- `/_config/installed.lock`

Then browse to `/install/` again.
