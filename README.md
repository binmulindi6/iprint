# iPrint

A tiny PHP app that turns one computer into a print station.

Anyone can upload a file (or paste a link) from their own device and gets a short **print code**. The person at the printer opens the admin side, finds the file by its code, opens it with the computer's own app (Preview, Word, ...) and prints it.

No framework, no build step. SQLite for metadata (created automatically).

## How it works

1. **Upload page (`/`)**: drag and drop files, pick them, or paste a direct link. Each drop gets a 5-character code (for example `AB3K7`). Files in the same drop are numbered `AB3K7-1`, `AB3K7-2`, ... The user gives the code to the person at the printer.
2. **Printer side (`/admin/`)**: sign in, type the code, and open the files. **Open on this computer** asks the server's OS to open the file in its default app; the operator prints from there.
3. **Reports (`/admin/report.php`)**: activity and page statistics, printable.

## Requirements

- PHP 7.3 or newer (8.x fine) with these extensions: `pdo_sqlite`, `curl`, `fileinfo`, `zip`, `zlib`
- Apache (MAMP works) or PHP's built-in server
- For "Open on this computer": the web server must run as the logged-in desktop user. MAMP does this by default.

## Install

Put the folder in your web root (for MAMP: `/Applications/MAMP/htdocs/iprint`), start Apache, and open:

- Upload page: `http://localhost/iprint/`
- Printer side: `http://localhost/iprint/admin/`

Other devices on the network use the computer's LAN address, for example `http://192.168.1.20/iprint/`.

Quick start without Apache:

```bash
php -S 0.0.0.0:8080 -t /Applications/MAMP/htdocs/iprint
```

The built-in server ignores `.htaccess`, so use Apache for real use (see Security).

Default login: **admin / admin**.

## Features

### Upload side
- Drag and drop, file picker, multiple files at once, per-file progress
- Paste a link: the server downloads the file (Google Drive share links are converted to direct downloads)
- One print code per drop, with a Copy button; page count shown after upload
- Allowed types: PDF, images (JPG, PNG, GIF, WebP, BMP, TIFF), DOC/DOCX, XLS/XLSX, PPT/PPTX, ODT/ODS/ODP, RTF, TXT, CSV (max 50 MB each)

### Printer side
- **Today's queue** (home): files not yet printed that were uploaded today
- **All files**: everything, with search (code or name), date picker with presets and a custom range, status filter (all / waiting / printed), file type filter, and sort
- Search by code lists that family in order, with an **Open all** button
- Grid or list view, image thumbnails, PDF/image preview, download
- **Multi-select** (checkbox, Ctrl/Cmd-click, Shift-click range, Ctrl/Cmd+A): open, mark printed, or delete several files at once. Files open in code and number order.
- **Mark as printed**. This cannot be undone: a printed file can't be marked as new again.
- The list refreshes itself every few seconds

### Page counts
Counted at upload and shown in the app and reports.

| Type | Source | Notes |
|---|---|---|
| PDF | the PDF's page tree | handles compressed page trees too |
| DOCX, ODT | page count saved in the file | accurate if last saved by Word / LibreOffice |
| PPTX, ODP | slide count | |
| Images | 1 page each | |
| XLSX, XLS, DOC, PPT, ODS, RTF, TXT, CSV | not counted (shown as "—") | page count depends on print layout |

### Reports
- Date range: quick presets plus custom From / To
- Cards: files uploaded, print codes, marked printed, **pages printed**, pages uploaded, opened on server, data received, deleted, files still waiting. Each compares with the previous period of equal length.
- Charts: activity over time, busiest hours, file types, upload source
- Activity log with filters and search; **CSV export**; **Print report** (clean print layout)
- "Pages printed" = pages of files marked printed (a file counts once). The app can't know how many copies were actually printed.
- Times use the viewing browser's time zone.

## Configuration

Edit [config.php](config.php):

| Key | Meaning |
|---|---|
| `admin_user` | admin username |
| `admin_pass_hash` | password hash. Generate one: `php -r 'echo password_hash("newpass", PASSWORD_DEFAULT);'` |
| `max_size` | max bytes per file (default 50 MB) |
| `allowed_ext` | allowed file extensions |
| `allow_remote_open` | `false` (default): "Open on this computer" only works when the admin is browsing from the server itself |
| `max_login_attempts`, `login_window` | login rate limit (failed attempts per IP per seconds) |

Upload size limits also depend on PHP. The root `.htaccess` sets 64 MB for `upload_max_filesize` and `post_max_size`; if uploads fail, raise them in MAMP's `php.ini`.

## Security

This is meant for a trusted local network. Still:

- Change the default `admin` password (`admin_pass_hash`).
- `storage/` and `src/` contain `.htaccess` files that block direct access. **Apache needs `AllowOverride All`** for these to work. Check by opening `/iprint/storage/app.sqlite` in a browser: it must be denied.
- Files are only served to a signed-in admin through `admin/file.php`.
- Uploads are checked by extension, size and real file type; stored names are safe; PHP and other executable types are rejected.
- Link fetching refuses private and local addresses, non-web ports, and limits redirects and size.
- Admin actions use CSRF tokens; login is rate limited; a print code only accepts uploads from the browser session that created it.
- "Open on this computer" launches files with the OS default app. Only expose the app to people you trust.

## Project layout

```
index.php            upload page
upload.php           upload / link / code-reservation endpoint
config.php           settings
admin/
  index.php          queue + all files
  report.php         reports page
  login.php, logout.php
  api.php            list / open / mark printed / delete (single or many)
  report_api.php     activity data for reports
  file.php           serves previews and downloads to the admin
src/
  bootstrap.php      session, SQLite, helpers, storing files, activity log
  fetch.php          safe download from a link
  pages.php          page counting
assets/              style.css, upload.js, admin.js, report.js
storage/             uploads/ and app.sqlite (created at runtime, not web-accessible)
```

## Data

- Files: `storage/uploads/<id>/<CODE>-<n>_<original name>`
- Database: `storage/app.sqlite` with `files`, `events` (activity log for reports; keeps its own copy of file details, so deleted files still appear), `batches` (codes) and `login_attempts`
- Older databases are upgraded automatically on first run (codes, page counts, activity history rebuilt from existing data)
- Back up the `storage/` folder to keep everything
- To use a different storage folder (for example for testing), set the `IPRINT_STORAGE` environment variable

## Not included (yet)

- Page counts for Excel and legacy Office files (would need LibreOffice)
- Silent printing to a chosen printer: printing is done by the operator from the app that opens the file
- Mobile-optimized admin layout
