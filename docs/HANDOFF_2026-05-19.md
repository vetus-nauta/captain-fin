# Captain Fin / Local Reports Handoff - updated 2026-05-20

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

Latest known commits after the 2026-05-20 input-window fixes:

- `captain-fin` main: `65ad56b` (`Sync signed note entries without duplicates`)
- `brkovic-ltd` main: `0dd9ee1` (`Update Captain Fin signed entry sync`)
- `Revoyacht` branch `work/builder-v2-next-20260508`: `06e6c95` (`Fix local report signed entry sync`)

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

## Signed Notes Input

The notes textarea is the source for quick financial entry lines with `+` and `-`.

Examples:

```text
+100 client payment
-40 fuel
+5 cash
```

Current behavior in both web/PWA and local desktop:

- typing or pasting signed lines immediately rebuilds the entries table below
- totals/metrics recalculate from the rebuilt entries immediately
- the `Разобрать + / -` button is a forced sync, not an append action
- repeated button presses must not duplicate entries
- trailing newlines in notes are preserved while editing
- web autosave must not rehydrate the active editor while the user is typing

Important implementation detail:

- Web app: `assets/app.js`, functions `syncSignedEntries`, `handleEditorInput`, `saveReport`
- Local desktop: `local_report_client/app.py`, embedded JS functions `syncSignedEntries`, `importSignedInput`

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
- stable autosave in the notes editor
- signed `+ / -` notes synced to entries without duplicates

Local desktop:

- SQLite local reports
- `web_id` mapping for web app sync
- pull from web app server storage over FTP
- local attachments
- local deleted archive
- summary for submitted reports
- storage info
- desktop layout preserved
- signed `+ / -` notes synced to entries without duplicates

## Recent Verification

Verified on 2026-05-20:

- Live web app version: `2026.05.20-captain-fin-010`
- Live marker checked at `https://brkovic.ltd/captain-fin/CAPTAIN_FIN_MARKER.json`
- Live `assets/app.js` checked and reports `APP_VERSION = '2026.05.20-captain-fin-010'`
- Local web app mirror tested on `127.0.0.1:18787`
- Local desktop app tested on temporary `127.0.0.1:18788` and normally runs on `127.0.0.1:8787`
- Local desktop `POST /api/pull-web-server` imports web report without duplication by `web_id`
- Systemd Drive sync timer is active
- Web/PWA signed-input regression passed:
  `+100 / -40` creates 2 rows and totals 100/40/60; pressing `Разобрать + / -` again keeps 2 rows; editing to three signed lines rebuilds 3 rows and totals 125/20/105.
- Local desktop signed-input regression passed:
  `+200 / -75` creates 2 rows and totals 200/75/125; pressing `Распределить заметки...` again keeps 2 rows; editing to three signed lines rebuilds 3 rows and totals 220/30/190.

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
