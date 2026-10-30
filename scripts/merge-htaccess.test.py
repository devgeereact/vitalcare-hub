#!/usr/bin/env python3
"""Tests for merge-htaccess.py. Run: python3 scripts/merge-htaccess.test.py"""
import importlib.util
import pathlib
import sys

spec = importlib.util.spec_from_file_location("m", pathlib.Path(__file__).with_name("merge-htaccess.py"))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

LIVE = """# === BEGIN Cloudflare-origin-lock (2026-08-24) ===
# comment lines with odd   spacing	and a tab
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP:CF-RAY} ^$
  RewriteRule ^ - [F,L]
</IfModule>
# === END Cloudflare-origin-lock ===
# The app
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^ index.html [L]
</IfModule>
<Files ".htaccess">
  Require all denied
</Files>
"""
passed = failed = 0


def check(label, ok):
    global passed, failed
    if ok:
        passed += 1
    else:
        failed += 1
        print("FAIL:", label)


def refused(text):
    try:
        m.merge(text)
    except SystemExit as e:
        return "REFUSED" in str(e)
    return False


out = m.merge(LIVE)
lock = LIVE[: LIVE.index(m.LOCK_END) + len(m.LOCK_END)]
check("origin lock is preserved byte for byte", lock in out)
check("origin lock comes first in the file", out.startswith(lock))
check("our block comes immediately after the lock", out[len(lock):].startswith(m.OURS_BEGIN))
check("the rest of the file is untouched", LIVE[len(lock):] in out)
check("our block is present exactly once", out.count(m.OURS_BEGIN) == 1 and out.count(m.OURS_END) == 1)
check("API routing rule is present", "RewriteRule ^api(?:/.*)?$ api/index.php [L]" in out)
check("Authorization pass-through is present", "E=HTTP_AUTHORIZATION" in out)
check("the API rule precedes the app fallback", out.index("api/index.php") < out.index("RewriteRule ^ index.html"))
check("the file ends with a newline", out.endswith("\n"))
check("running again changes nothing (idempotent)", m.merge(out) == out)
check("running three times changes nothing", m.merge(m.merge(out)) == out)

# An older copy of our block elsewhere is replaced, never duplicated, and edits inside it are discarded.
stale = LIVE + "\n" + m.OURS_BEGIN + "STALE EDIT\n" + m.OURS_END + "# tail\n"
out2 = m.merge(stale)
check("a stale copy of our block is replaced", "STALE EDIT" not in out2 and out2.count(m.OURS_BEGIN) == 1)
check("content after a stale block is kept", "# tail" in out2)

# Fail closed.
check("no origin lock: refused", refused("RewriteEngine On\nRewriteRule ^ index.html [L]\n"))
check("only the begin marker: refused", refused("# === BEGIN Cloudflare-origin-lock (x) ===\nstuff\n"))
check("end marker before begin: refused", refused(m.LOCK_END + "# === BEGIN Cloudflare-origin-lock (x) ===\n"))
check("our block without an end marker: refused", refused(LIVE + m.OURS_BEGIN + "x\n"))
check("empty file: refused", refused(""))
check("a file without a trailing newline is handled", m.merge(LIVE.rstrip("\n")).endswith("\n"))

print(f"merge-htaccess tests: {passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
