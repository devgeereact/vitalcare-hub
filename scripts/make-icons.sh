#!/usr/bin/env bash
# Rasterises public/logos/logo-round-navy.svg into the PWA icon files. macOS only (qlmanage, sips).
# The artwork is never redrawn or edited: it is scaled, and padded with white for the maskable icon.
set -euo pipefail
cd "$(dirname "$0")/.."
SRC=public/logos/logo-round-navy.svg
OUT=public/icons
TMP=.local-test/tmp/icons
mkdir -p "$OUT" "$TMP"
qlmanage -t -s 1024 -o "$TMP" "$SRC" >/dev/null 2>&1
BASE="$TMP/logo-round-navy.svg.png"
[ -s "$BASE" ] || { echo "qlmanage did not produce an image" >&2; exit 1; }

# "any" icons: the full round mark, small margin so edges never touch the canvas.
sips -z 480 480 "$BASE" --out "$TMP/m480.png" >/dev/null
sips --padToHeightWidth 512 512 --padColor FFFFFF "$TMP/m480.png" --out "$OUT/pwa-512.png" >/dev/null
sips -z 192 192 "$OUT/pwa-512.png" --out "$OUT/pwa-192.png" >/dev/null

# Maskable: artwork inside the central 80% safe zone on a solid white background.
sips -z 400 400 "$BASE" --out "$TMP/m400.png" >/dev/null
sips --padToHeightWidth 512 512 --padColor FFFFFF "$TMP/m400.png" --out "$OUT/pwa-maskable-512.png" >/dev/null
echo "icons written to $OUT"
