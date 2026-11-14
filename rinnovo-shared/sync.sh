#!/usr/bin/env bash
# Copy the shared Rinnovo modules into every Rinnovo Pro plugin (run after changing rinnovo-shared/).
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(dirname "$here")"
for pro in "$root"/rinnovo-*-pro; do
	[ -d "$pro" ] || continue
	mkdir -p "$pro/shared"
	rsync -a --delete "$here/invoices/" "$pro/shared/invoices/"
	echo "synced $(basename "$pro")"
done
