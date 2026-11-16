# OpenECC (main application)

Part of the [OpenECC repository](../README.md), which also contains the optional public [Check-In Gateway](../checkin-gateway/). The root README explains how the two work together.

OpenECC is a self-hosted web application for an Emergency Coordination Centre (ECC): incident and
operational-period setup, the ECC organization, ICS-compatible forms with sign-off and PDF export, resource and
facility tracking, finance and administration, check-in, and an ECC display. It supports the on-scene Incident Command
Post / Incident Management Team; it is not a field system for them. See
[What OpenECC is (and is not)](../README.md#what-openecc-is-and-is-not).

> **Free software, with no warranty.** OpenECC is free software: you may use, copy, change and share it under
> the terms of the [GNU Affero General Public License v3.0](../LICENSE.txt) (AGPL-3.0). It is provided **as is, without
> any warranty**. We don't promise that it works correctly, that it is free of errors, or that it is suitable for any
> particular purpose, including emergency management. You use it at your own risk. As far as the law allows, the
> authors and contributors are not liable for any loss or harm that comes from using it.
>
> Before you rely on it in a real emergency, test it and make sure it does what your organization needs, and keep a
> way of working without it, such as paper forms. The full terms, including the disclaimer of warranty and limitation
> of liability (sections 15 and 16), are in [LICENSE.txt](../LICENSE.txt).

> **Status: public beta (0.9.0-beta.5).** It is used operationally but has not had wide testing.
> Test your install, keep backups, and read [SECURITY.md](SECURITY.md) before putting real incident data in it.

* PHP 8 + MariaDB on Apache, no framework, no cloud dependency
* ICS Canada form templates filled and flattened to PDF with `pdftk`
* Local accounts (password for administrators, 6-digit PIN for incident staff); optional Microsoft Entra sign-in
* Optional public check-in gateway ([`../checkin-gateway`](../checkin-gateway/)): OpenECC polls it from inside your network once a minute

## Requirements

A fresh **Debian 12 or 13** server, root access, and about 1 GB RAM. The installer installs everything else
(Apache, MariaDB, PHP and its modules, pdftk, poppler, ghostscript, certbot when requested).

## Install

```bash
# as root, in the unpacked release folder
./install.sh
```

The installer asks where to install (default `/var/www/openecc`), the server name, the type of TLS
certificate (Let's Encrypt, self-signed, your own, or none), your time zone and the first administrator.
It then creates the database, Apache site and settings, and runs a self-check. Details: [docs/INSTALL.md](docs/INSTALL.md).

## Update

Unpack the new release somewhere else and run `./update.sh` as root. It backs up the database first and applies
database migrations. See [docs/UPDATING.md](docs/UPDATING.md).

## Documentation

* [docs/INSTALL.md](docs/INSTALL.md) - installation, TLS options, backups, uninstall
* [docs/UPDATING.md](docs/UPDATING.md) - upgrading, including from pre-0.9 private builds
* [docs/SMOKE_TEST.md](docs/SMOKE_TEST.md) - the built-in self-check
* [docs/FORMS.md](docs/FORMS.md) - official forms, redistribution and update checks
* [docs/EXTERNAL_CHECKIN.md](docs/EXTERNAL_CHECKIN.md), [docs/EMAIL_DELIVERY_SETUP.md](docs/EMAIL_DELIVERY_SETUP.md), [docs/ENTRA_SETUP_NOTES.md](docs/ENTRA_SETUP_NOTES.md)
* [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md), [CHANGELOG.md](CHANGELOG.md), [THIRD_PARTY.md](THIRD_PARTY.md)

## Source

https://github.com/OpenECC-Canada/OpenECC

## Licence

OpenECC is free software under the **GNU Affero General Public License v3.0** (AGPL-3.0). If you run a
modified version for users over a network you must offer them the corresponding source. It comes with **no warranty**
(see the notice at the top of this file). See `LICENSE.txt` and
[THIRD_PARTY.md](THIRD_PARTY.md) for bundled components and the ICS Canada forms.
