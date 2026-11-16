# Changelog

## 0.9.0 (2026-11-16)

Version numbers drop the "-beta.N" ending from here on: 0.9.0, then 0.9.1 and so on. The gateway keeps its own
version number, starting again at 0.9.0.

* Renamed: the gateway is now the **OpenECC Check-In Gateway**. Its folder (`/var/www/openecc-gateway`), settings
  (`/etc/openecc`), database and user (`openecc_gateway`), Apache site, logs and dispatch timer
  (`openecc-gateway-dispatch`) have new names. An old gateway can't be updated in place: remove it and install fresh
  (openecc/docs/MIGRATING.md). Sync headers are now `X-OpenECC-*`, so OpenECC 0.9.0 is required.

* README: says plainly that the gateway is provided as is, with no warranty (AGPL-3.0 sections 15 and 16).

* README: states plainly that the gateway is a limited check-in intake service, not a remote copy of Command Bridge or a field IMT system.

* **The installer and updater now say what each step is** (the step's name was cut off in the progress box).

## 0.9.0-beta.2

* New signed connection test `public/api/v1/sync/ping.php` (action `sync.ping`). It answers with the gateway version,
  server time, delivery mode and how many check-ins are waiting or stuck. It is read-only and never leases or changes
  events. Command Bridge 0.9.0-beta.3 uses it in `setup_checkin.sh --test`, during installation and in its self-check.
* New `bin/requeue_checkins.php`: lists check-ins the gateway stopped offering to Command Bridge (attempts used up, or
  rejected) and re-sends them with `--id=N` or `--all`. Written to the gateway audit log. Command Bridge ignores
  check-ins it already recorded, so re-sending is safe.
* New `tools/rotate_secret.sh`: installs a new key ID and shared secret from Command Bridge's `rotate_checkin_secret.sh`
  (hidden input, settings backed up first, offers to switch sync on if it is off).
* Installer: makes sure network time is on (signed requests more than 5 minutes off are refused). If you paste a secret
  from Command Bridge, *Accept synchronisation* now defaults to Yes. The final screen now explains the next step on
  Command Bridge (`setup_checkin.sh`) instead of listing settings to copy by hand, and lists the gateway's admin tools.
* Fixed: a settings file without `GATEWAY_TIMEZONE` made every page fail.
* Installer and updater mark `tools/*.sh` executable.

No database changes. Update with `update.sh`.

## 0.9.0-beta.1 - first public beta

First public release; version numbering reset (earlier private builds were 1.0.x, last 1.0.10) to match Command Bridge 0.9.0-beta.1.

* New installer (`install.sh`, whiptail dialogs with plain fallback): packages, own database and restricted account, Apache site,
  TLS (Let's Encrypt / self-signed / own certificate / none), settings file, generated shared secret, self-check.
  Default location `/var/www/commandbridge-gateway`; database `commandbridge_gateway`.
* New updater (`update.sh`) with automatic database backup and numbered migrations (`database/migrations/`).
* Single `database/schema.sql` baseline (includes all 1.0.9 and 1.0.10 changes); old `database_changes_*.sql` removed.
* Settings stay in `/etc/commandbridge/checkin_gateway.env`. Default time zone is now UTC and is set by the installer.
* Only `public/` is served. Apache rules deny config-like file types.
* Footer shows version, licence and source link (AGPL-3.0 section 13).
* Check-in page: the expected-resource list now refreshes in place while the page is open without losing in-progress entries.
* Carried over: escalating PIN throttle (per address, repeat offender, location-wide) and `bin/clear_lockouts.php`;
  pull-mode synchronisation API; companion-user (CIR/SAM) sync.

Upgrading a private 1.0.x gateway: see docs/UPDATING.md.
