#!/usr/bin/env bash
# Hosted demo on a VPS (Ubuntu + Docker compose v2). Run on the server as root
# from the repo copy (e.g. /opt/recurra/app). Step-by-step: docs/VPS.md.
#
#   docker/vps.sh init [domain]                    # once: secrets dir + stack.env (random DB password)
#   docker/vps.sh up                               # start / update the stack (re-applies the profile)
#   docker/vps.sh profile mainnet-demo|judge-demo  # switch store settings, products and charger
#   docker/vps.sh admin-password                   # set a new random admin password and show it once
#   docker/vps.sh status                           # profile, containers, jobs, charger log
#   docker/vps.sh logs [service]                   # follow logs (default: charger)
#   docker/vps.sh restart [service]                # restart one service (default: caddy)
#   docker/vps.sh forget-mainnet                   # switch to judge-demo and shred the mainnet charger key
#   docker/vps.sh wp <args>                        # wp-cli inside the stack
#   docker/vps.sh down                             # stop (keeps data volumes)
#
# Secrets live OUTSIDE the repo and the web root, in $RECURRA_SECRETS
# (default /opt/recurra/secrets, mode 700, files mode 600):
#   stack.env              RECURRA_DOMAIN, RECURRA_DB_PASSWORD, RECURRA_PROFILE, RECURRA_JUDGE_PASSWORD
#   charger.mainnet.env    copy of charger/.env.mainnet (mainnet-demo)
#   charger.judge.env      copy of charger/.env.judge   (judge-demo, testnet)
# Each charger file is mounted into the charger container only (compose secret).
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
SECRETS="${RECURRA_SECRETS:-/opt/recurra/secrets}"
STACK="$SECRETS/stack.env"
PROJECT="${RECURRA_PROJECT:-recurra}"
DEFAULT_DOMAIN="recurra.enoor.pp.ua"
# Shared judge login; public on purpose (README). The account is locked against changes.
JUDGE_PASSWORD="recurra-judge-2026"

die() { echo "error: $*" >&2; exit 1; }
say() { echo "[vps] $*"; }

get() { # key → value from stack.env
	sed -n "s/^$1=//p" "$STACK" | tail -n 1
}

set_kv() { # key value → stack.env
	if grep -q "^$1=" "$STACK"; then
		sed -i "s|^$1=.*|$1=$2|" "$STACK"
	else
		echo "$1=$2" >>"$STACK"
	fi
}

charger_file() { # profile → host env file of its charger
	case "$1" in
		mainnet-demo) echo "$SECRETS/charger.mainnet.env" ;;
		judge-demo) echo "$SECRETS/charger.judge.env" ;;
		*) die "unknown profile: $1 (mainnet-demo|judge-demo)" ;;
	esac
}

expected() { # profile → "network vault" the charger must use
	case "$1" in
		mainnet-demo) echo "mainnet recurra-vault.near" ;;
		judge-demo) echo "testnet vault-v2.recurra-farzad.testnet" ;;
	esac
}

need_init() {
	[ -f "$STACK" ] || die "$STACK not found; run: docker/vps.sh init"
}

lock_secrets() {
	chmod 700 "$SECRETS"
	find "$SECRETS" -maxdepth 1 -type f -exec chown root:root {} + -exec chmod 600 {} +
}

# No key files may sit in the repo copy (it is bind-mounted into containers).
check_repo_clean() {
	local found
	found="$(find "$REPO" \( -name node_modules -o -name target -o -name .git \) -prune -o \
		\( -name '.env*' ! -name '.env.example' -o -name '*.pem' -o -name '*-key.json' \) -print)"
	if [ -n "$found" ]; then
		die "secret-looking files inside the repo copy — move them to $SECRETS and retry:
$found"
	fi
}

compose() {
	need_init
	local profile env_host
	profile="$(get RECURRA_PROFILE)"
	env_host="$(charger_file "$profile")"
	[ -f "$env_host" ] || env_host=/dev/null
	CHARGER_ENV_HOST="$env_host" docker compose -p "$PROJECT" --env-file "$STACK" \
		-f "$REPO/docker/docker-compose.yml" -f "$REPO/docker/docker-compose.vps.yml" "$@"
}

tty_flag() { [ -t 0 ] && [ -t 1 ] || echo "-T"; }

start_charger() {
	local profile file net vault
	profile="$(get RECURRA_PROFILE)"
	file="$(charger_file "$profile")"
	compose --profile live rm -sf charger >/dev/null 2>&1 || true
	if [ ! -f "$file" ]; then
		say "charger NOT started: $file is missing (renewals will not be charged on this profile)"
		return 0
	fi
	read -r net vault <<<"$(expected "$profile")"
	grep -qx "NEAR_NETWORK=$net" "$file" || die "$file: NEAR_NETWORK is not $net"
	grep -qx "VAULT_ID=$vault" "$file" || die "$file: VAULT_ID is not $vault"
	compose --profile live up -d charger
	say "charger started for $profile ($net, $vault) — follow it with: docker/vps.sh logs"
}

cmd_init() {
	[ "$(id -u)" = 0 ] || die "run as root (sudo docker/vps.sh init)"
	local domain="${1:-$DEFAULT_DOMAIN}"
	[[ "$domain" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || die "invalid domain: $domain"
	command -v openssl >/dev/null || die "openssl is required"
	(umask 077 && mkdir -p "$SECRETS")
	if [ -f "$STACK" ]; then
		say "$STACK exists; keeping it"
	else
		(umask 077 && cat >"$STACK" <<EOF
RECURRA_DOMAIN=$domain
RECURRA_DB_PASSWORD=$(openssl rand -hex 24)
RECURRA_PROFILE=judge-demo
RECURRA_JUDGE_PASSWORD=$JUDGE_PASSWORD
EOF
		)
		say "created $STACK (random DB password, profile judge-demo)"
	fi
	lock_secrets
	check_repo_clean
	ls -l "$SECRETS"
}

cmd_up() {
	need_init
	lock_secrets
	check_repo_clean
	compose up -d --remove-orphans
	compose logs --no-log-prefix --tail 15 wpcli
	start_charger
	say "up: https://$(get RECURRA_DOMAIN) (profile $(get RECURRA_PROFILE))"
}

cmd_profile() {
	need_init
	local p="${1:-}"
	charger_file "$p" >/dev/null
	set_kv RECURRA_PROFILE "$p"
	compose run --rm -T wpcli sh /usr/local/bin/recurra-profile.sh "$p"
	start_charger
}

cmd_admin_password() {
	need_init
	local pw
	pw="$(openssl rand -hex 16)"
	# Sent on stdin so it never appears in a process list or container config.
	printf '%s\n' "$pw" | compose run --rm -T wpcli wp eval \
		'$u = get_user_by( "login", "admin" ); if ( ! $u ) { WP_CLI::error( "no admin user" ); } wp_set_password( trim( fgets( STDIN ) ), $u->ID );' >/dev/null
	cat <<EOF

  WordPress admin — shown once, not stored anywhere. Save it now.
    URL:      https://$(get RECURRA_DOMAIN)/wp-admin/
    user:     admin
    password: $pw

EOF
}

cmd_status() {
	need_init
	say "profile: $(get RECURRA_PROFILE)    domain: $(get RECURRA_DOMAIN)"
	ls -l "$SECRETS"
	compose --profile live ps --format 'table {{.Service}}\t{{.Status}}\t{{.Ports}}'
	say "store settings:"
	compose run --rm -T wpcli wp eval '$s = Recurra_Plugin::settings(); printf( "  mode %s · merchant %s · vault %s · chains %s\n", $s["mode"], $s["merchant_account"], $s["vault_contract"], $s["allowed_chains"] );'
	say "background jobs (Action Scheduler, group recurra):"
	compose run --rm -T wpcli wp action-scheduler action list --group=recurra --status=pending \
		--fields=hook,status,scheduled_date --format=table 2>/dev/null || true
	compose run --rm -T wpcli wp action-scheduler action list --group=recurra --status=complete \
		--fields=hook,status,scheduled_date --orderby=date --order=desc --per-page=4 --format=table 2>/dev/null || true
	say "env files inside the web root (expect none):"
	compose exec -T wordpress sh -c "find /var/www/html -name '.env*' | head -n 5; echo '  (end)'"
	say "charger (last 8 lines):"
	compose --profile live logs --no-log-prefix --tail 8 charger 2>/dev/null || echo "  (not running)"
}

cmd_forget_mainnet() {
	need_init
	local f="$SECRETS/charger.mainnet.env"
	if [ "$(get RECURRA_PROFILE)" = mainnet-demo ]; then
		say "switching to judge-demo first"
		cmd_profile judge-demo
	fi
	if [ -f "$f" ]; then
		shred -u "$f" 2>/dev/null || rm -f "$f"
		say "removed $f"
	else
		say "$f not present"
	fi
	say "mainnet key files left in $SECRETS: $(grep -l 'NEAR_NETWORK=mainnet' "$SECRETS"/* 2>/dev/null | wc -l)"
	say "optional: also delete that charge-only access key on-chain from your own machine (near account delete-keys …)"
}

case "${1:-}" in
	init) shift; cmd_init "$@" ;;
	up) cmd_up ;;
	profile) shift; cmd_profile "${1:-}" ;;
	admin-password) cmd_admin_password ;;
	status) cmd_status ;;
	logs) shift; compose --profile live logs -f --tail 50 "${1:-charger}" ;;
	restart) shift; compose restart "${1:-caddy}" ;;
	forget-mainnet) cmd_forget_mainnet ;;
	wp) shift; compose run --rm $(tty_flag) wpcli wp "$@" ;;
	down) compose --profile live down ;;
	*) sed -n '2,21p' "$0"; exit 2 ;;
esac
