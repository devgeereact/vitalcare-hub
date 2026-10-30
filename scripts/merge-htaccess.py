#!/usr/bin/env python3
"""Merge the vitalcare-hub rules into a live .htaccess without touching anything else.

  merge-htaccess.py LIVE_FILE > MERGED_FILE

Rules it enforces (it exits non-zero and writes nothing if any fails):
- The Cloudflare origin-lock block (between its BEGIN and END marker lines) must exist in the input,
  and appears in the output byte for byte.
- Everything outside our own block is passed through unchanged.
- Idempotent: running it again on its own output changes nothing.
- Our block is inserted directly after the origin lock, so the lock is always evaluated first.
"""
import re
import sys

LOCK_BEGIN = re.compile(r"^# === BEGIN Cloudflare-origin-lock.*$", re.M)
LOCK_END = "# === END Cloudflare-origin-lock ===\n"
OURS_BEGIN = "# === BEGIN vitalcare-hub (managed by scripts/merge-htaccess.py; do not edit by hand) ===\n"
OURS_END = "# === END vitalcare-hub ===\n"

OURS = OURS_BEGIN + """<IfModule mod_rewrite.c>
  RewriteEngine On

  # Hand the Authorization header to PHP (LiteSpeed and CGI strip it by default).
  RewriteCond %{HTTP:Authorization} .
  RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

  # The API: everything under /api/ goes to the PHP front controller, before the single-page-app
  # fallback below can answer it with index.html.
  RewriteCond %{REQUEST_URI} !^/api/index\\.php$
  RewriteRule ^api(?:/.*)?$ api/index.php [L]
</IfModule>
""" + OURS_END


def merge(text: str) -> str:
    if not text.endswith("\n"):
        text += "\n"
    begin = LOCK_BEGIN.search(text)
    end = text.find(LOCK_END)
    if begin is None or end == -1 or end < begin.start():
        raise SystemExit("REFUSED: the Cloudflare origin-lock block was not found; nothing written")
    lock_end = end + len(LOCK_END)
    lock = text[begin.start():lock_end]

    # Remove any earlier copy of our own block, wherever it is.
    i = text.find(OURS_BEGIN)
    if i != -1:
        j = text.find(OURS_END, i)
        if j == -1:
            raise SystemExit("REFUSED: found the start of our block but not its end; nothing written")
        text = text[:i] + text[j + len(OURS_END):]
        text = re.sub(r"\n{3,}", "\n\n", text)
        begin = LOCK_BEGIN.search(text)
        lock_end = text.find(LOCK_END) + len(LOCK_END)

    merged = text[:lock_end] + OURS + text[lock_end:]
    # Safety net: the lock must be intact, and still come first.
    if lock not in merged or merged.find(lock) > merged.find(OURS_BEGIN):
        raise SystemExit("REFUSED: merge would alter or reorder the origin lock; nothing written")
    return merged


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: merge-htaccess.py LIVE_FILE > MERGED_FILE")
    with open(sys.argv[1], encoding="utf-8") as fh:
        sys.stdout.write(merge(fh.read()))
