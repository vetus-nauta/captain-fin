#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO="${CAPTAIN_FIN_REPO:-git@github.com:vetus-nauta/captain-fin.git}"
PORT="${CAPTAIN_FIN_PORT:-18787}"

cd "$ROOT"

if [[ ! -d .git ]]; then
  git init
  git remote add origin "$REPO"
fi

git fetch origin main
git checkout main
git pull --ff-only origin main

printf 'Captain Fin local: http://127.0.0.1:%s/\n' "$PORT"
exec php -S "127.0.0.1:${PORT}" -t "$ROOT"

