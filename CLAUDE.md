# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

**Przechowalnia Opon** — a Polish tire storage management web app. Hosted on OVH shared hosting (Apache + PHP 8+ + MySQL). Frontend is vanilla JS (no build step, no framework). There is no local dev server — open HTML files directly in browser with `MOCK_MODE = true`, or test against a real server.

## Architecture

### Frontend
- Plain HTML pages + vanilla JS IIFE modules. No bundler, no npm scripts for the app itself.
- Each page is self-contained: `tires.html`, `customers.html`, `dashboard.html`, `templates.html`, `actions.html`, `users.html`, `login.html`, `reset-password.html`.
- Shared JS loaded via `<script>` tags in order: `api.js` → `auth.js` → `navbar.js` → (optional) `print.js` → inline `<script>`.

### JS modules
| File | Role |
|---|---|
| `js/api.js` | All HTTP calls. **`MOCK_MODE = true`** runs entirely in browser memory (no server needed). **This file is gitignored** — each environment keeps its own copy. |
| `js/auth.js` | `Auth.requireAuth()`, `Auth.can(user, perm)`, `Auth.logout()`. Also sets `data-theme` on `<html>` before render. |
| `js/navbar.js` | Renders the full navbar via `Navbar.render()`. Called on every page. Handles desktop dropdown + mobile two-panel layout (hamburger = nav links, avatar button = user account). |
| `js/print.js` | Label/receipt printing with QR code and barcode via CDN libs. |

### Backend
- `api/index.php` — single-entry router. Parses URL as `/{resource}/{id}/{sub}`. Uses `REDIRECT_URL` (OVH rewrite) falling back to `REQUEST_URI`.
- `api/config.php` — **gitignored**, must be uploaded manually. Template at `api/config/config.example.php`. Defines `DB_*`, `JWT_SECRET`, `MAIL_FROM`, `APP_URL`, `get_pdo()`.
- `api/helpers/auth.php` — `require_auth()` decodes JWT from `Authorization` header (OVH strips it; also checked via `REDIRECT_HTTP_AUTHORIZATION`). `require_permission($user, $perm)` enforces granular permissions.
- `api/helpers/mailer.php` — `send_mail()` uses custom SMTP (`api/helpers/smtp.php`, raw `stream_socket_client`) if configured in `settings` table, else falls back to PHP `mail()`.
- `api/cron/check-actions.php` — runs all active automation actions. Configure in OVH panel: *Hosting → Zadania zaplanowane*, once daily.

### Auth flow
JWT stored in `localStorage` (`po_token`, `po_session`) so the session survives closing the tab/browser. Token payload includes `id`, `username`, `role`, `permissions[]`, `exp` (30 days). `auth.js` decodes `exp` on load and logs out if expired; `api.js` redirects to login on any `401`. **Note:** the storage backend lives in the gitignored `js/api.js`, so switching sessionStorage↔localStorage there must be applied per-environment (not deployed via git). Permissions are stored in `user_permissions` table (not a column on `users`). The `manage_users` permission gates admin-only pages (Templates, Actions, Users).

### Adding a new API route
1. Create `api/routes/myroute.php` with `handle_myroute($method, $id, $body)`.
2. Add `case 'myroute':` to the `switch` in `api/index.php`.
3. Use `require_auth()` and optionally `require_permission()` at the top of the handler.

## Database schema (key tables)

```
users              — id, username, email, password (bcrypt), role, created_at
user_permissions   — user_id, permission  (UNIQUE)
tire_entries       — id, customer_id, license_plate, tire_*, location, date_in, status, date_out, next_tire_change, notes
customers          — id, full_name, phone, email
templates          — id, name, page_size, html_content  (print label templates)
email_templates    — id, name, subject, html_content
actions            — id, name, trigger_type, trigger_value, email_template_id, recipient_type, recipient_email, active, last_run
action_logs        — id, action_id, tire_id, recipient_email, status, error, sent_at
settings           — key, value  (SMTP config stored as smtp_host, smtp_port, etc.)
password_resets    — email, token, created_at, used
```

`find_or_create_customer()` in `tires.php` matches by `full_name + phone` and upserts `email` if provided.

## Deployment

Production is on OVH shared hosting at `tirehouse.oak-it.pl`. Branch `main` = production.

```bash
# On OVH server via SSH — force update to latest main
git fetch origin
git reset --hard origin/main
```

Files that must be uploaded manually via FTP (gitignored):
- `api/config.php` (copy from `api/config/config.example.php` and fill in values)
- `js/api.js` (set `MOCK_MODE = false` and correct API base URL)

Push develop → main:
```bash
git push origin develop:main
```

## OVH-specific quirks
- Apache strips the `Authorization` header — `.htaccess` re-injects it via `RewriteRule` as `HTTP_AUTHORIZATION`. `auth.php` checks both `getallheaders()` and `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']`.
- URL rewriting: `api/index.php` reads `REDIRECT_URL` (set by Apache after rewrite) not `REQUEST_URI`.
- No Composer — SMTP is implemented manually with `stream_socket_client` in `api/helpers/smtp.php`.
