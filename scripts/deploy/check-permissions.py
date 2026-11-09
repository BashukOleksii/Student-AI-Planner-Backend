#!/usr/bin/env python3
"""Read-only audit, run as www-data through the fixed privileged helper."""
import os
from pathlib import Path


def audit(root):
    def accessible(path, mode):
        if not os.access(path, mode):
            raise RuntimeError("Required Backend file/directory is inaccessible")

    def private(path, writable=False):
        mode = path.stat().st_mode
        if mode & 0o007:
            raise RuntimeError("Sensitive runtime path allows unrelated users")
        accessible(path, os.R_OK | (os.X_OK if path.is_dir() else 0)
                   | (os.W_OK if writable else 0))

    accessible(root, os.R_OK | os.X_OK)
    private(root / ".env")
    for name in ("artisan", "composer.json", "composer.lock", "bootstrap/app.php", "public/index.php", "vendor/autoload.php"):
        accessible(root / name, os.R_OK)
    for name in ("app", "bootstrap", "config", "database", "public", "resources", "routes", "vendor"):
        base = root / name
        if base.is_symlink():
            raise RuntimeError("Code directory must not be a symlink")
        accessible(base, os.R_OK | os.X_OK)
        for directory, dirs, files in os.walk(base):
            current = Path(directory)
            for entry in dirs + files:
                path = current / entry
                if path == root / "public/storage":
                    continue  # Existing Laravel public uploads link is server-owned.
                if path.is_symlink():
                    raise RuntimeError("Unexpected symlink in application code")
                accessible(path, os.R_OK | (os.X_OK if path.is_dir() else 0))
    for name in ("storage", "bootstrap/cache"):
        base = root / name
        if base.is_symlink():
            raise RuntimeError("Writable directory must not be a symlink")
        private(base, writable=True)
        for directory, dirs, files in os.walk(base):
            for entry in dirs + files:
                path = Path(directory) / entry
                if path.is_symlink():
                    raise RuntimeError("Unexpected symlink in writable directory")
                private(path, writable=path.is_dir())
    config = root / "bootstrap/cache/config.php"
    if config.exists():
        private(config)


if __name__ == "__main__":
    try:
        audit(Path("/srv/student-ai-planner/backend"))
    except (OSError, RuntimeError):
        raise SystemExit("Backend permission audit failed (details withheld)")
