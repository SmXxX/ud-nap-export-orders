#!/usr/bin/env bash
# Build a trimmed distribution zip of the plugin.
# Usage: ./build.sh [output-path]   (default: /tmp/ud-nap-orders-exporter.zip)

set -euo pipefail

SLUG="ud-nap-orders-exporter"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:-/tmp/${SLUG}.zip}"
STAGE="$(mktemp -d)"
DEST="${STAGE}/${SLUG}"

trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$DEST"

# Copy everything except dev/build artefacts.
rsync -a \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='.github' \
  --exclude='.idea' \
  --exclude='.vscode' \
  --exclude='.DS_Store' \
  --exclude='node_modules' \
  --exclude='*.log' \
  --exclude='claude.md' \
  --exclude='CLAUDE.md' \
  --exclude='build.sh' \
  --exclude='composer.lock' \
  "$SRC_DIR/" "$DEST/"

# Drop Oblique font variants — receipt PDF uses only regular + bold.
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Oblique.ttf"
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Oblique.ufm"
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-BoldOblique.ttf"
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-BoldOblique.ufm"

# Drop dompdf's cached .ufm.json metric files — regenerated on first PDF render.
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/"*.ufm.json

# Drop the 14 standard PDF font AFM files (Helvetica, Times, Courier, Symbol,
# ZapfDingbats). Receipt CSS forces "DejaVu Sans" so these are dead weight.
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/"Helvetica*.afm
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/"Times-*.afm
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/"Courier*.afm
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/"Symbol.afm
rm -f "$DEST/vendor/dompdf/dompdf/lib/fonts/"ZapfDingbats.afm

# NOTE: do NOT trim thecodingmachine/safe's generated/8.x/ dirs.
# The package routes multiple PHP versions to the same subdir (e.g. PHP 8.2 loads
# from generated/8.1/), so removing "unused" version dirs breaks the autoloader
# at runtime.

# Strip tests, docs, CI config from vendor packages.
find "$DEST/vendor" -type d \( \
    -iname 'tests' -o \
    -iname 'test' -o \
    -iname 'docs' -o \
    -iname 'doc' -o \
    -iname '.github' -o \
    -iname 'examples' -o \
    -iname 'example' \
  \) -prune -exec rm -rf {} +

find "$DEST/vendor" -maxdepth 4 -type f \( \
    -iname 'CHANGELOG*' -o \
    -iname 'UPGRADE*' -o \
    -iname 'phpunit.xml*' -o \
    -iname 'phpstan.neon*' -o \
    -iname '.php-cs-fixer*' -o \
    -iname '.editorconfig' -o \
    -iname '.gitattributes' -o \
    -iname '.gitignore' -o \
    -iname '.scrutinizer.yml' -o \
    -iname '.travis.yml' \
  \) -delete

# Drop every Markdown file anywhere in the build (README, CHANGELOG.md,
# project notes, etc). Distribution users don't need these.
find "$DEST" -type f -iname '*.md' -delete

# Produce the zip.
rm -f "$OUT"
( cd "$STAGE" && zip -rq "$OUT" "$SLUG" )

SIZE="$(du -h "$OUT" | awk '{print $1}')"
echo "Built: $OUT ($SIZE)"
