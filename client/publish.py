#!/usr/bin/env python3
"""Push AI-generated blog posts from this machine to WordPress via AI Blog Bridge.

Reads markdown files with YAML frontmatter (the format claude-blog and most
AI writing pipelines emit), maps the frontmatter to the plugin payload, and
POSTs it to /wp-json/ai-blog/v1/posts.

Usage:
    python publish.py post.md
    python publish.py post.md --status publish --toc
    python publish.py drafts/ --glob "*.md" --status draft
    python publish.py --ping
    python publish.py post.md --dry-run

Credentials are read from environment variables or a .env file next to this
script:
    WP_URL=https://example.com
    WP_USER=editor-login
    WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
    ABB_TOKEN=optional-shared-token
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import re
import sys
from pathlib import Path

try:
    import requests
except ImportError:  # pragma: no cover
    sys.exit("Missing dependency: pip install requests pyyaml")

try:
    import yaml
except ImportError:  # pragma: no cover
    sys.exit("Missing dependency: pip install requests pyyaml")


FRONTMATTER_RE = re.compile(r"^---\s*\n(.*?)\n---\s*\n?", re.DOTALL)
H1_RE = re.compile(r"^#\s+(.+)$", re.MULTILINE)
FAQ_HEADINGS = ("faq", "frequently asked questions", "preguntas frecuentes", "faqs")


# --------------------------------------------------------------------------- #
# Config
# --------------------------------------------------------------------------- #

def load_env(script_dir: Path) -> None:
    """Load KEY=VALUE lines from .env without adding a dependency."""
    env_file = script_dir / ".env"
    if not env_file.is_file():
        return
    for line in env_file.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def config() -> dict:
    """Two supported modes: an API token, or an Application Password."""
    url = os.environ.get("WP_URL", "").rstrip("/")
    user = os.environ.get("WP_USER", "")
    password = os.environ.get("WP_APP_PASSWORD", "")
    token = os.environ.get("ABB_TOKEN", "")

    if not url:
        sys.exit("Missing config: WP_URL (set it in .env or the environment)")

    if not token and not (user and password):
        sys.exit(
            "Missing credentials. Set ABB_TOKEN (from Settings -> AI Blog Bridge), "
            "or WP_USER plus WP_APP_PASSWORD."
        )

    return {
        "url": url,
        # Basic auth is skipped entirely in token mode.
        "auth": (user, password) if (user and password) else None,
        "token": token,
    }


def headers(cfg: dict) -> dict:
    head = {"Content-Type": "application/json", "Accept": "application/json"}
    if cfg["token"]:
        head["X-ABB-Token"] = cfg["token"]
    return head


# --------------------------------------------------------------------------- #
# Parsing
# --------------------------------------------------------------------------- #

def parse_file(path: Path) -> tuple[dict, str]:
    text = path.read_text(encoding="utf-8")
    match = FRONTMATTER_RE.match(text)

    if not match:
        return {}, text

    meta = yaml.safe_load(match.group(1)) or {}
    if not isinstance(meta, dict):
        meta = {}
    body = text[match.end():]
    return meta, body


def first(meta: dict, *keys, default=None):
    for key in keys:
        if key in meta and meta[key] not in (None, "", []):
            return meta[key]
    return default


def json_safe(value):
    """YAML parses 2026-09-01T09:00 into a datetime, which json cannot encode.

    Anything date-like becomes an ISO 8601 string, which is exactly what the
    plugin expects to hand to strtotime().
    """
    if isinstance(value, (datetime.datetime, datetime.date)):
        return value.isoformat()
    if isinstance(value, dict):
        return {k: json_safe(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [json_safe(v) for v in value]
    return value


def flatten_item(item):
    """YAML turns 'Some text: more text' into a dict. Put it back together."""
    if isinstance(item, dict):
        return " ".join(f"{k}: {v}" for k, v in item.items())
    return item


def as_list(value) -> list:
    if value in (None, "", []):
        return []
    if isinstance(value, str):
        return [part.strip() for part in value.split(",") if part.strip()]
    if isinstance(value, (list, tuple)):
        return [flatten_item(v) for v in value if v not in (None, "")]
    return [flatten_item(value)]


def strip_leading_h1(body: str) -> tuple[str, str | None]:
    """Remove a leading H1 and return it: WordPress renders the title itself."""
    stripped = body.lstrip("\n")
    match = H1_RE.match(stripped)
    if not match:
        return body, None
    return stripped[match.end():].lstrip("\n"), match.group(1).strip()


def extract_faq(body: str) -> tuple[str, list[dict]]:
    """Pull an H2 FAQ section out of the body into structured Q&A pairs."""
    lines = body.split("\n")
    start = None

    for index, line in enumerate(lines):
        match = re.match(r"^##\s+(.+)$", line.strip())
        if match and match.group(1).strip().lower().rstrip("?:").strip() in FAQ_HEADINGS:
            start = index
            break

    if start is None:
        return body, []

    end = len(lines)
    for index in range(start + 1, len(lines)):
        if re.match(r"^##\s+", lines[index].strip()):
            end = index
            break

    section = lines[start + 1:end]
    faq: list[dict] = []
    question: str | None = None
    answer: list[str] = []

    for line in section:
        heading = re.match(r"^###\s+(.+)$", line.strip())
        if heading:
            if question and answer:
                faq.append({"question": question, "answer": " ".join(answer).strip()})
            question = heading.group(1).strip()
            answer = []
        elif question and line.strip():
            answer.append(line.strip())

    if question and answer:
        faq.append({"question": question, "answer": " ".join(answer).strip()})

    if not faq:
        return body, []

    remaining = lines[:start] + lines[end:]
    return "\n".join(remaining).strip() + "\n", faq


# --------------------------------------------------------------------------- #
# Payload
# --------------------------------------------------------------------------- #

def build_payload(path: Path, args: argparse.Namespace) -> dict:
    meta, body = parse_file(path)
    body, h1 = strip_leading_h1(body)

    faq = first(meta, "faq", "faqs", default=[]) or []
    if not faq and not args.no_extract_faq:
        body, faq = extract_faq(body)

    title = first(meta, "title", "seo_title", default=h1 or path.stem.replace("-", " ").title())
    description = first(meta, "description", "meta_description", "excerpt", "summary", default="")
    slug = first(meta, "slug", "permalink", default=None)

    featured = first(meta, "featured_image", "image", "hero_image", "cover", "og_image")
    if isinstance(featured, str):
        featured = {"url": featured, "alt": first(meta, "image_alt", "hero_alt", default=title)}

    payload = {
        "external_id": first(meta, "external_id", "id", default=slug or path.stem),
        # Targets an existing post directly; without it a rewrite creates a duplicate.
        "post_id": first(meta, "post_id", "wp_post_id", default=None),
        "title": title,
        "content": body,
        "content_format": args.format,
        "excerpt": description,
        "status": args.status or first(meta, "status", default=None),
        "post_type": args.post_type or first(meta, "post_type", default=None),
        "slug": slug,
        "date": first(meta, "publish_date", "date", default=None),
        "author": args.author or first(meta, "author", default=None),
        "categories": as_list(first(meta, "categories", "category")),
        "tags": as_list(first(meta, "tags", "keywords")),
        "toc": args.toc or bool(first(meta, "toc", default=False)),
        "key_takeaways": as_list(first(meta, "key_takeaways", "takeaways")),
        "faq": faq,
        "sources": first(meta, "sources", "citations", default=[]) or [],
        "seo": {
            k: v
            for k, v in {
                "title": first(meta, "seo_title", "title", default=title),
                "description": description,
                "focus_keyword": first(meta, "focus_keyword", "primary_keyword", "target_keyword", default=""),
                "canonical": first(meta, "canonical", "canonical_url", default=""),
                "og_image": first(meta, "og_image", default="") or (featured or {}).get("url", ""),
            }.items()
            if v
        },
    }

    # On-page extras: internal links, tooltips, cornerstone, freshness stamp.
    internal_links = first(meta, "internal_links", "links", default=[]) or []
    if internal_links:
        payload["internal_links"] = internal_links

    tooltips = first(meta, "tooltips", default={}) or {}
    if isinstance(tooltips, dict) and tooltips:
        payload["tooltips"] = tooltips

    byline = first(meta, "byline", default=None)
    if isinstance(byline, dict) and byline.get("name"):
        payload["byline"] = byline

    if first(meta, "comment_status", default=None):
        payload["comment_status"] = first(meta, "comment_status")

    if first(meta, "cornerstone", "pillar", default=False):
        payload["cornerstone"] = True

    if args.updated_date or first(meta, "show_updated_date", default=False):
        payload["show_updated_date"] = True
        label = first(meta, "updated_label", default=None)
        if label:
            payload["updated_label"] = label

    prefix = first(meta, "image_tooltip_prefix", default="")
    if prefix:
        payload["image_tooltip_prefix"] = prefix

    if featured:
        payload["featured_image"] = featured

    schema = first(meta, "schema", "json_ld", "jsonld")
    if schema:
        payload["schema"] = schema

    author_meta = first(meta, "author_meta", "author_bio")
    if isinstance(author_meta, dict):
        payload["author_meta"] = author_meta

    if args.dry_run_local_images:
        payload["sideload_images"] = False

    # Drop empty values so plugin/site defaults win.
    return {k: json_safe(v) for k, v in payload.items() if v not in (None, "", [], {})}


# --------------------------------------------------------------------------- #
# Transport
# --------------------------------------------------------------------------- #

def endpoint(cfg: dict, route: str) -> str:
    return f"{cfg['url']}/wp-json/ai-blog/v1/{route}"


def ping(cfg: dict) -> int:
    response = requests.get(endpoint(cfg, "ping"), auth=cfg["auth"], headers=headers(cfg), timeout=30)
    print(json.dumps(response.json(), indent=2, ensure_ascii=False))
    return 0 if response.ok else 1


def stale(cfg: dict, days: int) -> int:
    response = requests.get(
        endpoint(cfg, "stale"),
        auth=cfg["auth"],
        headers=headers(cfg),
        params={"days": days},
        timeout=60,
    )
    if not response.ok:
        print(response.text[:500])
        return 1

    data = response.json()
    posts = data.get("posts", [])

    if not posts:
        print(f"No hay posts sin tocar en {days} dias.")
        return 0

    print(f"{len(posts)} posts sin actualizar en {days}+ dias (frescura = factor de ranking):\n")
    for post in posts:
        flag = " [cornerstone]" if post.get("cornerstone") else ""
        print(f"  #{post['post_id']:<6} {post['days_stale']:>4}d  {post['title'][:60]}{flag}")
        print(f"          {post['permalink']}")
    return 0


def audit_report(cfg: dict, post_id: int) -> int:
    response = requests.get(endpoint(cfg, f"audit/{post_id}"), auth=cfg["auth"], headers=headers(cfg), timeout=30)
    if not response.ok:
        print(response.text[:500])
        return 1

    data = response.json()
    print(f"{data.get('title')} — {data.get('permalink')}")
    print_audit(data)
    return 0


SEVERITY_MARK = {"error": "[X]", "warning": "[!]", "info": "[i]"}
SEVERITY_ORDER = {"error": 0, "warning": 1, "info": 2}


def print_audit(data: dict, indent: str = "     ") -> None:
    """Render the on-page audit returned with a publish."""
    audit = data.get("audit", data)
    findings = audit.get("findings", [])
    counts = audit.get("counts", {})

    if not findings:
        print(f"{indent}auditoria: sin hallazgos")
        return

    print(
        f"{indent}auditoria: {counts.get('error', 0)} errores, "
        f"{counts.get('warning', 0)} avisos, {counts.get('info', 0)} notas"
    )

    for finding in sorted(findings, key=lambda f: SEVERITY_ORDER.get(f.get("severity"), 3)):
        mark = SEVERITY_MARK.get(finding.get("severity"), "[?]")
        print(f"{indent}{mark} {finding.get('message')}")


def send(cfg: dict, payload: dict) -> tuple[bool, dict]:
    response = requests.post(
        endpoint(cfg, "posts"),
        auth=cfg["auth"],
        headers=headers(cfg),
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        timeout=180,
    )
    try:
        data = response.json()
    except ValueError:
        data = {"raw": response.text[:500]}
    return response.ok, data


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #

def collect_files(target: str, pattern: str) -> list[Path]:
    path = Path(target)
    if path.is_dir():
        return sorted(p for p in path.glob(pattern) if p.is_file())
    if path.is_file():
        return [path]
    sys.exit(f"Not found: {target}")


def main() -> int:
    # Windows consoles default to a legacy codepage; accented output would be mangled.
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")

    load_env(Path(__file__).resolve().parent)

    parser = argparse.ArgumentParser(description="Publish markdown blog posts to WordPress via AI Blog Bridge.")
    parser.add_argument("target", nargs="?", help="Markdown file or directory")
    parser.add_argument("--glob", default="*.md", help="Glob used when target is a directory (default: *.md)")
    parser.add_argument("--status", choices=["draft", "pending", "publish", "future", "private"], help="Post status")
    parser.add_argument("--post-type", help="Target post type (default: plugin setting)")
    parser.add_argument("--author", help="Author login, email or ID")
    parser.add_argument("--format", default="markdown", choices=["markdown", "html", "blocks"], help="Content format")
    parser.add_argument("--toc", action="store_true", help="Insert a table of contents block")
    parser.add_argument("--no-extract-faq", action="store_true", help="Keep the FAQ section inline instead of converting it to schema")
    parser.add_argument("--dry-run", action="store_true", help="Print the payload without sending it")
    parser.add_argument("--dry-run-local-images", action="store_true", help="Skip media sideloading on the server")
    parser.add_argument("--updated-date", action="store_true", help="Print a visible last-updated line at the top of the post")
    parser.add_argument("--ping", action="store_true", help="Check credentials and site configuration")
    parser.add_argument("--stale", nargs="?", type=int, const=365, metavar="DAYS", help="List posts not updated in DAYS (default 365)")
    parser.add_argument("--audit", type=int, metavar="POST_ID", help="Show the stored on-page audit for a post")

    args = parser.parse_args()
    cfg = config()

    if args.ping:
        return ping(cfg)

    if args.stale is not None:
        return stale(cfg, args.stale)

    if args.audit:
        return audit_report(cfg, args.audit)

    if not args.target:
        parser.error("a markdown file or directory is required (or use --ping / --stale / --audit)")

    files = collect_files(args.target, args.glob)
    if not files:
        sys.exit("No matching files.")

    failures = 0
    for path in files:
        payload = build_payload(path, args)

        if args.dry_run:
            print(f"--- {path.name} ---")
            print(json.dumps(payload, indent=2, ensure_ascii=False))
            continue

        ok, data = send(cfg, payload)
        if ok:
            print(f"[ok] {path.name} -> #{data.get('post_id')} ({data.get('action')}, {data.get('status')}) {data.get('permalink', '')}")

            injected = data.get("internal_links", {}).get("injected", [])
            if injected:
                print(f"     enlaces internos inyectados: {', '.join(injected)}")

            for warning in data.get("warnings", []):
                print(f"     warning: {warning}")

            print_audit(data)

            if data.get("inspect_link"):
                print(f"     indexar: {data['inspect_link']}")
        else:
            failures += 1
            print(f"[fail] {path.name}: {data.get('message') or data}")

    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
