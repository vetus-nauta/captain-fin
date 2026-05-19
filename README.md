# Captain Fin

Private financial notes and report web app for BRKOVIC.

## Run Locally

```bash
php -S 127.0.0.1:18787 -t .
```

Open:

```text
http://127.0.0.1:18787/
```

## Deploy Path

```text
https://brkovic.ltd/captain-fin/
```

The app is private:

- `robots.txt` blocks crawlers.
- `.htaccess` sends `X-Robots-Tag: noindex, nofollow, noarchive`.
- guests see only the login/placeholder screen.
- login uses the existing BRKOVIC admin credentials through `https://brkovic.ltd/api/auth/login`.

## Data

Reports are stored as JSON:

```text
storage/reports/YYYY/*.json
```

Excel exports are stored by year:

```text
storage/exports/YYYY/*.xlsx
```

Exports try to duplicate to Google Drive with `rclone` and folder id:

```text
1x9m41AUYPocx7H0UezF_lZnFvzWO54zQ
```

If `rclone` is unavailable on the server, the app still writes local server files and skips the Drive copy.

## Local Update Marker

`CAPTAIN_FIN_MARKER.json` is the version marker for local localhost mirrors.

## Sync On Another PC

```bash
scripts/sync-local.sh
```

The script fetches the latest GitHub version and starts a localhost server.

