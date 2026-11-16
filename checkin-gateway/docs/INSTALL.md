# Installing the Check-In Gateway

The gateway is a separate, public-facing server. OpenECC itself stays internal and exchanges signed messages with it.

## Requirements
* A fresh Debian 12 or 13 server with root access, reachable from the internet on ports 80 and 443.
* A DNS name pointing at it (for example `checkin.example.ca`) - needed for Let's Encrypt.
* OpenECC 0.9.0-beta.1 or newer for the other end.

## Install
```bash
sudo ./install.sh
```
Questions: install folder (default `/var/www/openecc-gateway`), server name, HTTPS mode (Let's Encrypt recommended;
OpenECC only accepts a gateway certificate it trusts, so not self-signed), time zone, delivery mode (**pull**
recommended: OpenECC contacts the gateway, so it needs no inbound access), the shared-secret key ID and secret, and
whether to accept synchronisation immediately. The installer also switches on network time: signed requests are refused
when the two servers' clocks differ by more than 5 minutes.

**The shared secret:** if OpenECC's `setup_checkin.sh` already generated one, choose **No** at *Generate a strong
random shared secret?*, paste it, enter the same key ID, and answer **Yes** to accepting synchronisation. Otherwise let the
installer generate one; its last screen shows the address, key ID and secret (shown once, not written to the log).

## Then, on OpenECC
Run `sudo <OpenECC folder>/tools/setup_checkin.sh`: paste the values from the gateway's last screen, or use
`--test` if OpenECC generated the key. It tests the connection and offers to switch sync on.
See [openecc/docs/EXTERNAL_CHECKIN.md](../../openecc/docs/EXTERNAL_CHECKIN.md) for the full walkthrough.
Then check that a CIR/SAM user can sign in only at an authorised location with their own PIN, and that a revoked user cannot.

## Where things go
| Item | Location |
|---|---|
| Application | `<install folder>`; only `public/` is served |
| Settings | `/etc/openecc/checkin_gateway.env` (root:www-data 0640) |
| Database | `openecc_gateway` (the app account has SELECT/INSERT/UPDATE/DELETE only) |
| Apache site | `/etc/apache2/sites-available/openecc-gateway.conf` |
| Dispatcher (push mode only) | systemd `openecc-gateway-dispatch.timer` |

## Operations
* Health: `https://your-gateway/health.php` returns `{"ok":true}`.
* Lockouts: `sudo php <install folder>/bin/clear_lockouts.php --list`.
* Check-ins that stopped retrying: `sudo php <install folder>/bin/requeue_checkins.php --list`, then `--id=N` or `--all`.
* New shared secret: start on OpenECC with `tools/rotate_checkin_secret.sh`; it tells you when to run
  `sudo <install folder>/tools/rotate_secret.sh` here.
* Connection test from OpenECC: `sudo <OpenECC folder>/tools/setup_checkin.sh --test` (uses `/api/v1/sync/ping.php`).
* Logs: `/var/log/apache2/openecc-gateway-error.log`, install log `/var/log/openecc-gateway-install.log`.
* Uninstall: `a2dissite openecc-gateway`, remove the folder, `/etc/openecc/checkin_gateway.env`, `gateway-install.conf`, and drop database/user `openecc_gateway`.
