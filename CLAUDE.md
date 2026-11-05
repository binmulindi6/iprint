# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

iPrint: a framework-free PHP app (SQLite, vanilla JS) that makes one computer a print station. Users upload files and get a 5-char print code; the operator signs in at `/admin/`, finds files by code and opens them in the server's OS default app (`open` / `start` / `xdg-open`) to print. See `README.md` for features and config.

## Commands

There is no build, package manager, linter or test suite. Needs PHP 7.3+ with `pdo_sqlite`, `curl`, `fileinfo`, `zip`, `zlib`. Keep PHP code 7.3-compatible (no `match`, union types, `str_contains`).

```bash
# Run (or just use MAMP at http://localhost/iprint/). The built-in server ignores .htaccess.
php -S 127.0.0.1:8099 -t /Applications/MAMP/htdocs/iprint

# Run against a throw-away storage dir (uploads + app.sqlite) so real data is untouched
IPRINT_STORAGE=$(mktemp -d) php -S 127.0.0.1:8099 -t .

# Syntax checks
php -l path/to/file.php
node --check assets/admin.js
```

**Never run tests or cleanup against the default `storage/`**: it holds the user's real uploads and `app.sqlite`. Always set `IPRINT_STORAGE` for experiments, and never `rm` anything in `storage/`.

Manual API testing is done with `curl` + cookie jars: log in at `admin/login.php` (needs the CSRF token from the form; default creds `admin`/`admin`), then call `admin/api.php` with an `X-CSRF-Token` header (the token is embedded in `admin/index.php` as `window.IPRINT.csrf`). Uploads need a session that first reserved the code (`action=reserve` on `upload.php`).

## Architecture

- **`src/bootstrap.php`** is included by every entry point. It loads `config.php`, starts the session, defines `BASE` (URL prefix, works from `/` or `/admin/`), `UPLOAD_DIR`, `DB_FILE` (both under `IPRINT_STORAGE` or `storage/`), and holds the shared logic: `db()` (creates tables and runs idempotent migrations inline), `store_file()`, `delete_file_row()`, `log_event()`, auth/CSRF helpers. Schema changes go in `db()` as `ALTER TABLE` guarded by `PRAGMA table_info`; migrations must be safe to re-run and create dependent tables first.
- **Print codes / families**: each upload batch reserves a code in `batches` (`new_batch_code`). `store_file()` assigns `seq` inside a `BEGIN IMMEDIATE` transaction, giving labels `CODE-1`, `CODE-2`... Files live at `storage/uploads/<id>/<CODE>-<seq>_<original name>`. A code is only accepted for uploads from the session that reserved it (`$_SESSION['batches']`).
- **Two tables matter**: `files` (current state; `status` is `new` or `printed`) and `events` (append-only activity log: upload/open/printed/unprinted/delete, with its own snapshot of name, size, pages). Reports read only `events`, so deleted files still show up. Any new action that changes files must call `log_event()`.
- **Upload path**: `upload.php` (POST: `action=reserve`, or `file`/`url` + `code`) → `store_file()` (extension allow-list from config, finfo MIME check for PDF/images, size cap, page count via `src/pages.php`). Links go through `src/fetch.php`, which pins DNS to a public IP and follows redirects manually (SSRF protection); keep that when editing.
- **Admin API** (`admin/api.php`): `list` (GET), and POST actions `mark`, `delete`, `open`, each accepting `id` or `ids[]`, CSRF via `X-CSRF-Token`. `mark` only allows `printed` (printed files can't go back to new, by design). `open` shells out to the OS and is restricted to the server machine unless `allow_remote_open`.
- **Files are never served directly**: `storage/` and `src/` are blocked by `.htaccess`; `admin/file.php` serves previews/downloads to signed-in admins only.
- **Frontend is client-side state**: `assets/admin.js` fetches the whole file list (polls every 8 s) and does all filtering, views (Today's queue vs All files), selection and bulk actions in the browser. `assets/report.js` fetches raw events from `admin/report_api.php` and does all aggregation, charts (hand-built SVG) and CSV in the browser, using the browser's time zone. So a change to what the UI shows is usually in the JS, not PHP; `public_row()` in `bootstrap.php` defines the file fields the JS receives.
- **Page counts** (`src/pages.php`) are pure PHP: PDF page tree (including object streams), `docProps/app.xml` for DOCX/PPTX, `meta.xml` for ODF, 1 for images, `null` otherwise. "Pages printed" in reports is computed from `events.pages`.

## Conventions

- Escape all output with `h()`; return JSON through `json_out()`.
- Mutating admin endpoints require CSRF; admin pages call `require_admin()` (`require_admin(true)` for JSON endpoints).
