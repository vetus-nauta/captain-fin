#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${CAPTAIN_FIN_PORT:-18787}"
URL="http://127.0.0.1:${PORT}/"

if ! ss -ltn 2>/dev/null | grep -q "127.0.0.1:${PORT}"; then
  nohup php -S "127.0.0.1:${PORT}" -t "$ROOT" > "$ROOT/storage/captain-fin-local.log" 2>&1 &
  sleep 0.4
fi

if command -v google-chrome >/dev/null 2>&1; then
  exec google-chrome --class=CaptainFin --name=CaptainFin --app="$URL"
fi

exec xdg-open "$URL"

