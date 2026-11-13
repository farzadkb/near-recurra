# Host the demo on a VPS

The public demo at https://recurra.enoor.pp.ua runs the same Docker stack as the local demo, plus [Caddy](https://caddyserver.com) for automatic HTTPS (`docker/docker-compose.vps.yml`). Everything is driven by `docker/vps.sh` on the server.

```
Internet ──80/443──▶ caddy ──▶ wordpress ──▶ db          (only caddy publishes ports)
                                  ▲
                    cron (WP-Cron + Action Scheduler every 30 s)
                    charger (calls charge() on the vault; key = compose secret)
```

**Server layout**

| Path | What | Mode |
| --- | --- | --- |
| `/opt/recurra/app` | copy of this repo (no `.env*`, no `target/`, no `node_modules/`) | |
| `/opt/recurra/secrets/stack.env` | domain, random DB password, active profile, judge password | 600 |
| `/opt/recurra/secrets/charger.judge.env` | testnet charger key (judge-demo) | 600 |
| `/opt/recurra/secrets/charger.mainnet.env` | mainnet charger key (mainnet-demo, only while recording) | 600 |

The secrets directory is mode 700 and owned by root. Each charger file is mounted into the charger container only, as a compose secret at `/run/secrets/charger_env`; WordPress, the database and Caddy never see it. `docker/vps.sh up` refuses to start if any `.env*`, `*.pem` or `*-key.json` file sits inside the repo copy, and Caddy answers `/.env*`, `/.git*`, `/xmlrpc.php` and `/wp-config.php*` with 404.

**Profiles**

| | `judge-demo` (default) | `mainnet-demo` |
| --- | --- | --- |
| Checkout | mock mode (no funds), all chains | **live** 1Click, Arbitrum + Base USDC → `recurra-shop.near` |
| Subscriptions | testnet `vault-v2.recurra-farzad.testnet`, plan 2 (Pro plan, mUSDC faucet) | mainnet `recurra-vault.near`, plan 1 (Demo plan, 1 USDC / 5 min) |
| Products shown | Coffee beans, Digital sticker pack, Pro plan (testnet) | Demo item ($1), Demo plan ($1 / 5 min) |
| Store notice | faucet instructions on every page | none |
| Charger | `charger.judge.env` (testnet) | `charger.mainnet.env` (mainnet) |

A profile switch keeps orders and the 1Click API key. Orders keep the vault they were made on, so testnet subscriptions keep syncing against testnet after a switch to mainnet, and the reverse also holds.

## Steps

Run the commands marked **(local)** from the repo root on your machine, and the ones marked **(VPS)** as root on the server. Commands below use `VPS=root@recurra.enoor.pp.ua`; with a sudo user, prefix the server commands with `sudo`.

**1. Prepare the directories (local)**

```sh
VPS=root@recurra.enoor.pp.ua
ssh $VPS 'mkdir -p /opt/recurra/app && install -d -m 700 /opt/recurra/secrets'
```

**2. Copy the repo without secrets or build output (local)**

```sh
rsync -az --delete \
  --exclude .git/ --exclude target/ --exclude node_modules/ --exclude '.env*' \
  --exclude '*.pem' --exclude '*-key.json' --exclude '.balances-snapshot.json*' --exclude .claude/ \
  ./ $VPS:/opt/recurra/app/
ssh $VPS "find /opt/recurra/app -name '.env*' -o -name '*.pem' -o -name '*-key.json'"
```

→ the second command prints nothing (about 3 MB copied).

**3. Copy only the charger key you need (local)**

The key is streamed over SSH into a file that is created with mode 600, so it is never world-readable, even briefly:

```sh
ssh $VPS 'umask 077; cat > /opt/recurra/secrets/charger.judge.env' < charger/.env.judge        # judge-demo (testnet)
ssh $VPS 'umask 077; cat > /opt/recurra/secrets/charger.mainnet.env' < charger/.env.mainnet    # mainnet-demo, only for recording
```

**4. Start the stack (VPS)**

```sh
cd /opt/recurra/app
docker/vps.sh init        # once: creates /opt/recurra/secrets/stack.env (random DB password, profile judge-demo)
docker/vps.sh up
```

→ The first run takes about 2–3 minutes (it pulls images and downloads WooCommerce). It ends with:

```
[recurra] judge-demo: Pro plan (testnet) ($1) → plan 2 on vault-v2.recurra-farzad.testnet
[recurra] ready: https://recurra.enoor.pp.ua — profile judge-demo, HPOS on
[vps] charger started for judge-demo (testnet, vault-v2.recurra-farzad.testnet) — follow it with: docker/vps.sh logs
```

**5. Admin password (VPS)**

```sh
docker/vps.sh admin-password
```

→ prints `user: admin` and a random 32-character password once. The password is not stored anywhere, and `admin`/`admin` does not work on this stack. Run the command again to rotate it.

**6. Verify HTTPS (local or anywhere)**

```sh
curl -sI https://recurra.enoor.pp.ua/ | grep -iE '^(HTTP|strict-transport|via)'
curl -sI http://recurra.enoor.pp.ua/  | grep -iE '^(HTTP|location)'
curl -svI https://recurra.enoor.pp.ua/ 2>&1 | grep -i 'issuer:'
for p in /xmlrpc.php /.env /wp-json/wp/v2/users '/?author=1'; do curl -s -o /dev/null -w "%{http_code} $p\n" "https://recurra.enoor.pp.ua$p"; done
```

→ expected:

```
HTTP/2 200
strict-transport-security: max-age=31536000
via: 1.1 Caddy
HTTP/1.1 308 Permanent Redirect
Location: https://recurra.enoor.pp.ua/
*  issuer: C=US; O=Let's Encrypt; CN=…
404 /xmlrpc.php
404 /.env
404 /wp-json/wp/v2/users
404 /?author=1
```

In a browser, the shop shows the faucet notice and a padlock without mixed-content warnings. Log in at `/my-account/` as `customer` / `recurra-judge-2026`.

**7. Switch profiles (VPS)**

```sh
docker/vps.sh profile mainnet-demo
```

→ expected:

```
[recurra] mainnet-demo: Demo item ($1)
[recurra] mainnet-demo: Demo plan ($1) → plan 1 on recurra-vault.near
[recurra] profile mainnet-demo: checkout live → recurra-shop.near · subscriptions on recurra-vault.near · chains arb,base
[vps] charger started for mainnet-demo (mainnet, recurra-vault.near) — follow it with: docker/vps.sh logs
```

The plan link is checked against the chain (merchant, active, price = plan amount). If the check fails, the product stays a draft and the command exits with the reason. Switch back with `docker/vps.sh profile judge-demo`. If a profile's charger file is missing, you see `charger NOT started: … is missing`, and the store works but renewals are not charged.

**8. Background jobs and the charger (VPS)**

```sh
docker/vps.sh status      # profile, containers (only caddy has ports), store settings, recurra jobs, last charger lines
docker/vps.sh logs        # follow the charger (Ctrl+C to stop); `docker/vps.sh logs caddy` for Caddy
```

→ In `status`, `recurra_poll` and `recurra_renewals` show a `pending` run about one minute ahead and `complete` runs every minute, the web-root check lists no `.env` files, and the charger prints `charger … → <vault> on <network>` and then `nothing due` or `sub N: charged https://…nearblocks.io/txns/…` once per minute.

**9. After recording: remove the mainnet key (VPS)**

```sh
docker/vps.sh forget-mainnet
```

→ This switches to judge-demo if needed, shreds `charger.mainnet.env`, and prints `mainnet key files left in /opt/recurra/secrets: 0`. To revoke that charge-only key on-chain as well, list the keys from your own machine with `near account list-keys recurra-shop.near network-config mainnet now`, then delete the function-call key that has method `charge` with `near account delete-keys`.

**Update the code later:** repeat step 2, then run `docker/vps.sh up`, which re-applies the active profile. Data volumes survive `docker/vps.sh down` and reboots. Every service except the one-shot `wpcli` restarts automatically.

## If the Let's Encrypt certificate fails

Start with `docker/vps.sh logs caddy`. On success you see `certificate obtained successfully` for `recurra.enoor.pp.ua`. Otherwise:

1. **DNS:** `dig +short A recurra.enoor.pp.ua` must print the VPS IPv4 address, which you can see on the VPS with `ip -4 addr`. `dig +short AAAA recurra.enoor.pp.ua` must print nothing, or this server's IPv6 address. Let's Encrypt prefers IPv6, so a stale AAAA record breaks validation.
2. **Cloudflare:** the record must be **DNS only** (grey cloud). The orange-cloud proxy terminates TLS itself, and validation then hits Cloudflare instead of Caddy.
3. **Ports:** both 80 and 443 must be reachable from the internet, through ufw and also through the provider's firewall or security group. Check from your machine with `curl -sI http://recurra.enoor.pp.ua/`, which must answer `308` from Caddy. On the VPS, `ss -tlnp | grep -E ':(80|443) '` must show `docker-proxy`. If another web server holds the port, stop it.
4. **Retry now:** Caddy retries by itself with backoff. After a fix, `docker/vps.sh restart caddy` retries immediately.
5. **Rate limits** (after many failed attempts the log shows `too many failed authorizations` or `rateLimited`): test against the Let's Encrypt staging CA, then switch back.

   ```sh
   echo 'CADDY_GLOBAL_OPTIONS=acme_ca https://acme-staging-v02.api.letsencrypt.org/directory' >> /opt/recurra/secrets/stack.env
   docker/vps.sh up && docker/vps.sh logs caddy     # → certificate obtained (staging; browsers will warn)
   sed -i '/^CADDY_GLOBAL_OPTIONS=/d' /opt/recurra/secrets/stack.env
   docker/vps.sh up && docker/vps.sh logs caddy     # → certificate obtained (production)
   ```

   Caddy also falls back to ZeroSSL by itself when Let's Encrypt refuses.
