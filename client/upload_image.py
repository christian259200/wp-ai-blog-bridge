#!/usr/bin/env python3
"""Optimise an image and upload it straight into the WordPress media library.

Why this exists: the plugin can sideload an image from a URL, but that makes
the *server* download it. On shared hosting a 4K PNG from an image generator
blows past the PHP time and memory limits and the request dies on a hosting
error page. Doing the work locally is faster, produces a web-appropriate file,
and hands WordPress a small upload it can always handle.

Prints the attachment ID, which goes into the post payload as:

    featured_image:
      attachment_id: 1234

Usage:
    python upload_image.py <url-or-path> --alt "..." [--caption "..."]
                           [--title "..."] [--filename nombre.jpg]
                           [--width 1600] [--quality 82]
"""

from __future__ import annotations

import argparse
import io
import os
import sys
from pathlib import Path

try:
    import requests
    from PIL import Image
except ImportError:  # pragma: no cover
    sys.exit("Missing dependency: pip install requests pillow")

from publish import config, load_env  # reuse the same .env handling


def fetch(source: str) -> bytes:
    if source.startswith(("http://", "https://")):
        response = requests.get(source, timeout=120)
        response.raise_for_status()
        return response.content
    return Path(source).read_bytes()


def optimise(raw: bytes, max_width: int, quality: int) -> bytes:
    image = Image.open(io.BytesIO(raw))

    # Flatten transparency onto white: hero images are never transparent, and
    # JPEG cannot carry an alpha channel anyway.
    if image.mode in ("RGBA", "LA", "P"):
        image = image.convert("RGBA")
        canvas = Image.new("RGB", image.size, (255, 255, 255))
        canvas.paste(image, mask=image.split()[-1])
        image = canvas
    else:
        image = image.convert("RGB")

    if image.width > max_width:
        height = round(image.height * max_width / image.width)
        image = image.resize((max_width, height), Image.LANCZOS)

    buffer = io.BytesIO()
    image.save(buffer, format="JPEG", quality=quality, optimize=True, progressive=True)
    return buffer.getvalue()


def upload(cfg: dict, data: bytes, filename: str, alt: str, caption: str, title: str) -> dict:
    endpoint = f"{cfg['url']}/wp-json/wp/v2/media"

    head = {
        "Content-Disposition": f'attachment; filename="{filename}"',
        "Content-Type": "image/jpeg",
    }
    if cfg["token"]:
        head["X-ABB-Token"] = cfg["token"]

    response = requests.post(
        endpoint, auth=cfg["auth"], headers=head, data=data, timeout=180
    )
    response.raise_for_status()
    media = response.json()

    fields = {k: v for k, v in (("alt_text", alt), ("caption", caption), ("title", title)) if v}
    if fields:
        requests.post(
            f"{endpoint}/{media['id']}", auth=cfg["auth"], json=fields, timeout=60
        ).raise_for_status()

    return media


def main() -> int:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")

    load_env(Path(__file__).resolve().parent)

    parser = argparse.ArgumentParser(description="Optimise and upload an image to WordPress.")
    parser.add_argument("source", help="Image URL or local path")
    parser.add_argument("--alt", default="", help="Alt text (write it, it is not optional for SEO)")
    parser.add_argument("--caption", default="", help="Caption shown under the image")
    parser.add_argument("--title", default="", help="Title attribute / media library name")
    parser.add_argument("--filename", default="", help="Filename on the server; use keywords")
    parser.add_argument("--width", type=int, default=1600, help="Max width in pixels (default 1600)")
    parser.add_argument("--quality", type=int, default=82, help="JPEG quality (default 82)")

    args = parser.parse_args()
    cfg = config()

    raw = fetch(args.source)
    data = optimise(raw, args.width, args.quality)

    filename = args.filename or "imagen.jpg"
    if not filename.lower().endswith((".jpg", ".jpeg")):
        filename = os.path.splitext(filename)[0] + ".jpg"

    media = upload(cfg, data, filename, args.alt, args.caption, args.title)

    print(f"origen:     {len(raw) / 1024:.0f} KB")
    print(f"optimizada: {len(data) / 1024:.0f} KB  ({args.width}px, calidad {args.quality})")
    print(f"attachment_id: {media['id']}")
    print(f"url: {media['source_url']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
