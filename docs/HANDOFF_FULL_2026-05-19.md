# Captain Fin / Local Reports Handoff - 2026-05-19

## What This Is

Captain Fin is the web/PWA version of the financial reports app.
The local desktop app is `local_report_client`, a separate localhost desktop-style UI for the same report workflow.

The web app is now the source of truth for shared/mobile use. The local desktop app can pull server data from the web app storage over FTP and keep its own local SQLite copy.

## Live URLs

- Web app: `https://brkovic.ltd/captain-fin/`
- Local web app mirror on this PC: `http://127.0.0.1:18787/`
- Local desktop app on this PC: `http://127.0.0.1:8787/`

## Repositories

- Main web app repo: `git@github.com:vetus-nauta/captain-fin.git`
- BRKOVIC site repo copy: `git@github.com:vetus-nauta/brkovic-ltd.git`
- Local desktop app source currently lives in:
  `/home/alexey/GitHub/Revoyacht/local_report_client`

## Web App Server Storage

The live server stores data under:

```text
/home/brkovic/public_html/captain-fin/storage
```

Specific paths:

```text
Reports:
/home/brkovic/public_html/captain-fin/storage/reports/YYYY/*.json

Deleted archive:
/home/brkovic/public_html/captain-fin/storage/trash/YYYY/*.json

Attachments:
/home/brkovic/public_html/captain-fin/storage/attachments/YYYY/report-id/*

Excel exports:
/home/brkovic/public_html/captain-fin/storage/exports/YYYY/*.xlsx
```

The web app API also exposes these paths through `api/?action=storage-info` after login.

## Local Desktop Storage

```text
SQLite:
/home/alexey/GitHub/Revoyacht/local_report_client/reports.sqlite3

Attachments:
/home/alexey/GitHub/Revoyacht/local_report_client/attachments

Deleted archive:
/home/alexey/GitHub/Revoyacht/local_report_client/deleted

Excel exports:
/home/alexey/GitHub/Revoyacht/local_report_client/exports
```

## Sync Model

Web app report ids are strings like:

```text
cf-20260519-191414-c8b704
```

The local desktop app uses numeric SQLite ids, but stores the web id in the `web_id` column.
This is what prevents duplicate imports.

Desktop sync endpoint:

```text
POST http://127.0.0.1:8787/api/pull-web-server
```

The desktop button `Подтянуть web app` now calls this server-side FTP pull. It does not depend on browser cookies.

## Google Drive Duplication

Google Drive folder:

```text
https://drive.google.com/drive/folders/1x9m41AUYPocx7H0UezF_lZnFvzWO54zQ
```

The live hosting PHP attempts `rclone` if available, but the reliable configured sync is on this PC:

```text
/home/alexey/GitHub/captain-fin/scripts/server-drive-sync.py
```

Systemd user timer:

```text
captain-fin-drive-sync.timer
```

It runs every 10 minutes and copies submitted reports, attachments, and Excel exports from the FTP server to Google Drive.

Check it with:

```bash
systemctl --user list-timers captain-fin-drive-sync.timer --no-pager
systemctl --user status captain-fin-drive-sync.timer --no-pager
```

Run manually:

```bash
/home/alexey/GitHub/captain-fin/scripts/server-drive-sync.py
```

## Desktop App

Start manually:

```bash
cd /home/alexey/GitHub/Revoyacht
python3 local_report_client/app.py --port 8787
```

Current intended layout is desktop/two-column:

- left column: report list
- right column: report editor, metrics, entries, attachments, exports

Do not convert this app to the mobile split-screen layout. That split belongs to the web/PWA.

## Features Implemented

Web app:

- PWA for iOS/Android install
- BRKOVIC admin login reuse
- compact mobile two-screen workflow
- submitted checkbox and dimmed cards
- protected report storage by year
- deleted archive
- attachments
- summary for submitted reports by period/all time
- professional Excel report template
- server storage info
- share web app

Local desktop:

- SQLite local reports
- `web_id` mapping for web app sync
- pull from web app server storage over FTP
- local attachments
- local deleted archive
- summary for submitted reports
- storage info
- desktop layout preserved

## Recent Verification

Verified on 2026-05-19:

- Live web app version: `2026.05.19-captain-fin-008`
- Local desktop app starts on `127.0.0.1:8787`
- Local desktop `POST /api/pull-web-server` imports web report without duplication by `web_id`
- Systemd Drive sync timer is active

## Backup

Backup created on 2026-05-19 under:

```text
/home/alexey/GitHub/Revoyacht/backups
```

It includes:

- web app source
- local desktop app source
- local SQLite database
- local exports/attachments/deleted archive
- live server storage downloaded from FTP

