#!/usr/bin/env bash
# Build an installable plugin zip honouring .distignore.
# Usage: bash bin/build-zip.sh [output-dir]
set -euo pipefail
SLUG="upi-direct-for-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/build}"
VERSION="$(grep -m1 -E '^\s*\* Version:' "$ROOT/$SLUG.php" | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')"
STAGE="$(mktemp -d)"
mkdir -p "$STAGE/$SLUG" "$OUT"
OUT="$(cd "$OUT" && pwd)"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/$SLUG/"
( cd "$STAGE" && zip -qr "$OUT/$SLUG-$VERSION.zip" "$SLUG" )
rm -rf "$STAGE"
echo "Built $OUT/$SLUG-$VERSION.zip"
