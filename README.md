# MSPDashboard - Phase 1 Vertical Slice

This implementation provides:
- TV-friendly dashboard page (`/`) with one tile: **Unassigned tickets**.
- Admin login + settings (`/admin/login.php`, `/admin/settings.php`).
- API endpoint (`/api/dashboard`) with server-side cache and Halo API status.
- IP allowlist middleware on all pages and API routes.
- Encrypted storage of Halo `client_secret` in MySQL using an app key file outside webroot.

## File/Folder Structure

```text
MSPDashboard/
├── config/
│   └── app.php
├── database/
│   └── 001_phase1.sql
├── public/
│   ├── .htaccess
│   ├── index.php
│   ├── assets/dashboard.css
│   ├── admin/
│   │   ├── login.php
│   │   ├── logout.php
│   │   └── settings.php
│   └── api/
│       ├── .htaccess
│       └── dashboard.php
├── scripts/
│   └── generate_app_key.php
└── src/
    ├── bootstrap.php
    ├── Core/
    │   ├── ApiCacheRepository.php
    │   ├── Auth.php
    │   ├── Database.php
    │   ├── EncryptionService.php
    │   └── SettingsRepository.php
    ├── Middleware/
    │   └── IpAllowlistMiddleware.php
    └── Services/
        ├── DashboardService.php
        └── HaloClient.php
```

## MySQL Migration

Run:

```sql
SOURCE database/001_phase1.sql;
```

This creates:
- `admin_users`
- `settings`
- `api_cache`

Default admin login:
- Username: `admin`
- Password: `ChangeMe123!`

## cPanel Deployment Steps

1. Upload repository files to your account.
2. Set document root to the `public/` folder.
3. Create MySQL database + user, import `database/001_phase1.sql`.
4. Generate encryption key file **outside webroot**:
   ```bash
   php scripts/generate_app_key.php /home/<cpanel-user>/mspdashboard_app.key
   chmod 600 /home/<cpanel-user>/mspdashboard_app.key
   ```
5. Configure environment variables in cPanel (or Apache include):
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
   - `APP_KEY_FILE` (path from step 4)
6. Ensure PHP extensions are enabled:
   - `pdo_mysql`
   - `curl`
   - `sodium`
7. Visit `/admin/login.php`, sign in, and save settings.
8. Confirm `/api/dashboard` returns JSON and `/` displays the tile.

## Halo Endpoint Confirmation Notes (Important)

`src/Services/HaloClient.php` includes TODO markers where Halo endpoint details must be confirmed:
- Token request currently posts to: `{{halo_auth_base_url}}/token` with `client_credentials`.
- Unassigned metric currently calls placeholder path:
  - `{{halo_resource_base_url}}/api/reports/unassigned-count`

To finalize against your Halo tenant:
1. Confirm OAuth token endpoint path and required `scope`/tenant parameters.
2. Replace the placeholder unassigned endpoint path/query in `HaloClient::fetchUnassignedCount()`.
3. Update the JSON mapping in `fetchUnassignedCount()` if the response key is not `count` or `unassignedCount`.

## API Response Shape

`GET /api/dashboard` returns:
- `apiStatus.halo.state` = `green|amber|red`
- `apiStatus.halo.message`
- `tiles.unassignedCount`
- `updatedAt.halo`
- `updatedAt.dashboard`
- `meta.cache` details

If Halo fails:
- returns last cached value when available
- status becomes `amber` (cached fallback)
- status becomes `red` when no cache exists

## Frontend Polling

The dashboard polls `/api/dashboard` every 10 seconds and updates the Unassigned tile without page reload.
