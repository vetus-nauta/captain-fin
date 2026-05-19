#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FOLDER_ID="${CAPTAIN_FIN_DRIVE_FOLDER_ID:-1x9m41AUYPocx7H0UezF_lZnFvzWO54zQ}"

if ! command -v rclone >/dev/null 2>&1; then
  echo "rclone is not installed" >&2
  exit 1
fi

rclone copy "$ROOT/storage/reports" gdrive:reports --drive-root-folder-id "$FOLDER_ID"
rclone copy "$ROOT/storage/exports" gdrive:exports --drive-root-folder-id "$FOLDER_ID"

echo "Captain Fin Drive sync complete."

