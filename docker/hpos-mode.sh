#!/bin/sh
# Switches the Docker store's order storage. Usage (stack running):
#   docker compose -f docker/docker-compose.yml run --rm wpcli sh /usr/local/bin/hpos-mode.sh <on|off> <sync|nosync>
# on/off   = HPOS (custom order tables) authoritative or legacy posts.
# sync     = WooCommerce "compatibility mode" (keeps both stores in sync).
# Orders are synced first so that switching never loses data.
set -eu
cd /var/www/html
HPOS="${1:-on}"; SYNC="${2:-nosync}"
case "$HPOS/$SYNC" in on/sync|on/nosync|off/sync|off/nosync) ;; *) echo "usage: hpos-mode.sh <on|off> <sync|nosync>"; exit 2 ;; esac

# Bring both stores in line before switching the authoritative one.
wp wc hpos compatibility-mode enable >/dev/null 2>&1 || true
wp wc hpos sync >/dev/null 2>&1 || true
if [ "$HPOS" = on ]; then
	wp wc hpos enable >/dev/null
else
	wp wc hpos disable >/dev/null
fi
if [ "$SYNC" = nosync ]; then
	wp wc hpos compatibility-mode disable >/dev/null
fi
wp wc hpos status | grep -E "HPOS enabled|Compatibility mode|Unsynced"
