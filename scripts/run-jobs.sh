#!/bin/sh
# Runs WordPress cron + Recurra's background jobs (Action Scheduler group
# "recurra": backup payment poller, subscription renewals) every N seconds.
# For local/dev sites where WP-Cron only fires on page visits. In production use
# a system cron instead (README → Running in production).
#
#   scripts/run-jobs.sh /var/www/html/near        # every 60 s
#   scripts/run-jobs.sh /var/www/html/near 30     # every 30 s
#
# When started as root, WP-CLI runs as the owner of wp-content (e.g. www-data),
# so files created by jobs (logs, caches) stay writable by PHP-FPM.
set -u
WP_PATH="${1:?usage: run-jobs.sh <wordpress-path> [interval-seconds]}"
INTERVAL="${2:-60}"
[ -f "$WP_PATH/wp-config.php" ] || { echo "no wp-config.php in $WP_PATH" >&2; exit 2; }

run_wp() {
	if [ "$(id -u)" = 0 ]; then
		owner="$(stat -c %U "$WP_PATH/wp-content")"
		if [ "$owner" != root ] && command -v sudo >/dev/null 2>&1; then
			sudo -u "$owner" wp --path="$WP_PATH" "$@"
			return
		fi
		wp --allow-root --path="$WP_PATH" "$@"
		return
	fi
	wp --path="$WP_PATH" "$@"
}

echo "running WP-Cron + Action Scheduler group 'recurra' for $WP_PATH every ${INTERVAL}s (Ctrl+C to stop)"
while :; do
	# Due WP-Cron events (WooCommerce housekeeping, the Action Scheduler queue runner).
	run_wp cron event run --due-now --quiet || echo "$(date -u +%FT%TZ) wp cron failed" >&2
	# Recurra's actions explicitly, even if the queue runner above skipped them.
	run_wp action-scheduler run --group=recurra --quiet || echo "$(date -u +%FT%TZ) action-scheduler failed" >&2
	echo "$(date -u +%FT%TZ) recurra jobs ran"
	sleep "$INTERVAL"
done
