#!/usr/bin/env python3
"""Build the WordPress-installable plugin zip.

Windows PowerShell 5.1's Compress-Archive writes entry paths with backslashes,
which the ZIP spec does not allow. WordPress then unpacks a single oddly named
file instead of a plugin folder and activation fails with "the plugin file does
not exist". Python's zipfile always writes forward slashes, so this script is
the supported way to package the plugin.

It also keeps local-only material out of the archive: the client folder holds
.env with live WordPress credentials, which must never reach the server.

Usage:
    python build-zip.py
"""

from __future__ import annotations

import argparse
import sys
import zipfile
from pathlib import Path

# Must match the folder the plugin already occupies on the live site. This is
# NOT the name of the project directory: the install was renamed to
# ai-blog-bridge to get past a corrupt copy, and packaging under the old
# project name made WordPress install a second, separate plugin next to the
# running one. Two active copies redeclare the same classes and fatal the site.
DEFAULT_SLUG = "ai-blog-bridge"

# Only what WordPress needs to run the plugin.
INCLUDE_FILES = ["ai-blog-bridge.php", "uninstall.php", "README.md"]
INCLUDE_DIRS = ["includes", "assets", "examples"]

# Never ship these, whatever else changes.
EXCLUDE_NAMES = {".env", ".gitignore", ".DS_Store", "Thumbs.db"}
EXCLUDE_DIR_NAMES = {"__pycache__", "client", "tests", ".git"}
EXCLUDE_SUFFIXES = {".pyc", ".pyo", ".log", ".zip"}


def should_skip(path: Path) -> bool:
    if path.name in EXCLUDE_NAMES or path.suffix in EXCLUDE_SUFFIXES:
        return True
    return any(part in EXCLUDE_DIR_NAMES for part in path.parts)


def main() -> int:
    parser = argparse.ArgumentParser(description="Package the plugin for WordPress.")
    parser.add_argument(
        "--slug",
        default=DEFAULT_SLUG,
        help="Folder name inside the zip. Change it to install alongside a broken copy.",
    )
    args = parser.parse_args()
    slug = args.slug

    root = Path(__file__).resolve().parent
    output = root.parent / f"{slug}.zip"

    entry_paths: list[tuple[Path, str]] = []

    for name in INCLUDE_FILES:
        source = root / name
        if not source.is_file():
            sys.exit(f"Missing required file: {name}")
        entry_paths.append((source, f"{slug}/{name}"))

    for folder in INCLUDE_DIRS:
        base = root / folder
        if not base.is_dir():
            continue
        for source in sorted(base.rglob("*")):
            if not source.is_file() or should_skip(source):
                continue
            relative = source.relative_to(root).as_posix()
            entry_paths.append((source, f"{slug}/{relative}"))

    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
        for source, arcname in entry_paths:
            # as_posix() above guarantees forward slashes; assert it stays true.
            assert "\\" not in arcname, f"Backslash in entry: {arcname}"
            archive.write(source, arcname)

    size_kb = output.stat().st_size / 1024
    print(f"{output}")
    print(f"{len(entry_paths)} archivos, {size_kb:.0f} KB")

    with zipfile.ZipFile(output) as archive:
        bad = [n for n in archive.namelist() if "\\" in n]
        if bad:
            sys.exit(f"Backslashes leaked into: {bad[:3]}")
        leaked = [n for n in archive.namelist() if n.endswith(".env")]
        if leaked:
            sys.exit(f"Credentials leaked into: {leaked}")
        print("verificado: separadores '/', sin .env, sin client/")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
