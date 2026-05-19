#!/usr/bin/env python3
import json
import os
import re
import subprocess
import tempfile
from ftplib import FTP, error_perm
from pathlib import Path

HOST = os.environ.get("CAPTAIN_FIN_FTP_HOST", "brkovic.ltd")
USER = os.environ.get("CAPTAIN_FIN_FTP_USER", "brkovic")
SECRET_FILE = Path(os.environ.get("CAPTAIN_FIN_SECRET_FILE", "/home/alexey/GoogleDrive/FOR CODEX/Копия пароли brkovic.ltd.md"))
REMOTE_STORAGE = os.environ.get("CAPTAIN_FIN_REMOTE_STORAGE", "/public_html/captain-fin/storage")
DRIVE_FOLDER_ID = os.environ.get("CAPTAIN_FIN_DRIVE_FOLDER_ID", "1x9m41AUYPocx7H0UezF_lZnFvzWO54zQ")


def ftp_password() -> str:
    lines = [line.strip().strip("*` ") for line in SECRET_FILE.read_text(encoding="utf-8", errors="ignore").splitlines()]
    for index, line in enumerate(lines):
        if line == USER and index + 1 < len(lines):
            return lines[index + 1].replace("\\!", "!")
    raise RuntimeError("FTP password not found")


def list_names(ftp: FTP, root: str) -> list[str]:
    try:
        return [Path(name).name for name in ftp.nlst(root) if Path(name).name not in (".", "..")]
    except error_perm:
        return []


def list_files(ftp: FTP, root: str) -> list[str]:
    files = []
    current = ftp.pwd()
    try:
        ftp.cwd(root)
        for name in ftp.nlst():
            name = Path(name).name
            if name in (".", ".."):
                continue
            try:
                ftp.size(name)
                files.append(root.rstrip("/") + "/" + name)
            except error_perm:
                pass
    finally:
        ftp.cwd(current)
    return files


def download(ftp: FTP, remote: str, local: Path) -> None:
    local.parent.mkdir(parents=True, exist_ok=True)
    with local.open("wb") as handle:
        ftp.retrbinary("RETR " + remote, handle.write)


def rclone_copyto(local: Path, remote: str) -> None:
    subprocess.run(
        ["rclone", "copyto", str(local), "gdrive:" + remote, "--drive-root-folder-id", DRIVE_FOLDER_ID],
        check=True,
        stdout=subprocess.DEVNULL,
    )


def sync_attachment_dir(ftp: FTP, report_id: str, year: str, tmp: Path) -> None:
    root = f"{REMOTE_STORAGE}/attachments/{year}/{report_id}"
    for remote in list_files(ftp, root):
        rel = remote.removeprefix(root + "/")
        local = tmp / "attachments" / year / report_id / rel
        download(ftp, remote, local)
        rclone_copyto(local, f"attachments/{year}/{report_id}/{rel}")


def main() -> None:
    ftp = FTP(HOST, timeout=45)
    ftp.login(USER, ftp_password())
    uploaded = 0
    with tempfile.TemporaryDirectory(prefix="captain-fin-drive-") as raw_tmp:
        tmp = Path(raw_tmp)
        for year in list_names(ftp, REMOTE_STORAGE + "/reports"):
            if not re.match(r"^\d{4}$", year):
                continue
            for remote in list_files(ftp, f"{REMOTE_STORAGE}/reports/{year}"):
                if not remote.endswith(".json"):
                    continue
                local = tmp / "reports" / year / Path(remote).name
                download(ftp, remote, local)
                report = json.loads(local.read_text(encoding="utf-8"))
                if not report.get("submitted"):
                    continue
                report_id = str(report.get("id") or local.stem)
                rclone_copyto(local, f"reports-submitted/{year}/{report_id}.json")
                sync_attachment_dir(ftp, report_id, year, tmp)
                uploaded += 1
        for year in list_names(ftp, REMOTE_STORAGE + "/exports"):
            if not re.match(r"^\d{4}$", year):
                continue
            for remote in list_files(ftp, f"{REMOTE_STORAGE}/exports/{year}"):
                if not re.search(r"\.(xlsx|xls)$", remote, re.I):
                    continue
                rel = remote.removeprefix(REMOTE_STORAGE + "/exports/")
                local = tmp / "exports" / rel
                download(ftp, remote, local)
                rclone_copyto(local, f"exports/{rel}")
    ftp.quit()
    print(f"Captain Fin Drive sync complete: {uploaded} submitted reports.")


if __name__ == "__main__":
    main()
