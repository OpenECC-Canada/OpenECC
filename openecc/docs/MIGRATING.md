# Moving from Command Bridge to OpenECC

Command Bridge was renamed **OpenECC** in version 0.9.0. The rename includes the names inside an install: folders,
services, the database and its user, Apache sites and logs. An old Command Bridge install can't be updated in place.
`update.sh` and `install.sh` stop with a message pointing here if they find one.

You move your data with the in-app **Backup** and **Restore**. The tables are the same, so a backup from Command Bridge
restores into OpenECC.

| | Command Bridge | OpenECC |
|---|---|---|
| Repo / release folder | `command-bridge/` | `openecc/` |
| Install folder | `/var/www/commandbridge` | `/var/www/openecc` |
| Settings | `/etc/commandbridge` | `/etc/openecc` |
| Database and user | `commandbridge` | `openecc` |
| Apache site, logs | `commandbridge.conf`, `commandbridge-*.log` | `openecc.conf`, `openecc-*.log` |
| Check-in sync timer | `commandbridge-external-checkin-sync.*` | `openecc-external-checkin-sync.*` |
| Gateway database | `commandbridge_gateway` | `openecc_gateway` |
| Gateway folder, site | `/var/www/commandbridge-gateway`, `commandbridge-gateway.conf` | `/var/www/openecc-gateway`, `openecc-gateway.conf` |
| Gateway timer | `commandbridge-gateway-dispatch.*` | `openecc-gateway-dispatch.*` |
| Sync headers | `X-CommandBridge-*` | `X-OpenECC-*` (both servers must be OpenECC) |

## Before you start

1. **Update Command Bridge to its last version** (0.9.0-beta.4 with migrations up to 0019) so its tables match
   OpenECC's. Run `update.sh` from the last Command Bridge release as usual.
2. **If you use the Check-In Gateway**, let the last check-ins come through. In Command Bridge, open
   Settings > Check-in Sync Status and make sure nothing is waiting.
3. **Make a backup.** In Command Bridge, go to Settings > Backup & Restore and download a backup. It contains the
   database and uploaded files. Keep it somewhere safe, off the server.
4. **Keep a database dump as well**, as a second copy:
   ```bash
   sudo mariadb-dump --single-transaction commandbridge | gzip > ~/commandbridge-final.sql.gz
   ```

## Main server: remove Command Bridge, install OpenECC

You can also install OpenECC on a new server and skip the removal. If you do, the old server stays as it was.

**1. Switch off the old site and timer:**
```bash
sudo a2dissite commandbridge
sudo a2disconf commandbridge-hardening
sudo systemctl disable --now commandbridge-external-checkin-sync.timer 2>/dev/null
sudo rm -f /etc/systemd/system/commandbridge-external-checkin-sync.*
sudo systemctl daemon-reload
sudo systemctl reload apache2
```

**2. Move the old files out of the way.** This is a rename, not a delete, so you can go back:
```bash
sudo mv /etc/commandbridge /etc/commandbridge.old
sudo mv /var/www/commandbridge /var/www/commandbridge.old
sudo mv /etc/apache2/sites-available/commandbridge.conf /etc/apache2/sites-available/commandbridge.conf.old
for v in /etc/php/*/; do sudo mv "$v"apache2/conf.d/90-commandbridge.ini "$v"apache2/conf.d/90-commandbridge.ini.old 2>/dev/null; sudo mv "$v"cli/conf.d/90-commandbridge.ini "$v"cli/conf.d/90-commandbridge.ini.old 2>/dev/null; done
```
The old database stays where it is until you remove it in step 5.

**3. Install OpenECC.** Unpack the new release, then:
```bash
cd OpenECC/openecc
sudo ./install.sh
```
Use the same web address (server name) as before. If you choose Let's Encrypt, certbot gets a certificate for that
name again (or reuses the one it already has).

**4. Restore your data.** Sign in as the administrator the installer created. Go to Settings > Backup & Restore,
choose the backup from "Before you start" and restore it. After the restore, sign in with your **old** accounts.
If the menu still says "Command Bridge", change the Application name under Settings > Organization & Branding.

**5. When everything works** (give it a few days), remove the old pieces:
```bash
sudo mariadb -e "DROP DATABASE commandbridge; DROP USER 'commandbridge'@'localhost'; DROP USER 'commandbridge'@'127.0.0.1';"
sudo rm -rf /etc/commandbridge.old /var/www/commandbridge.old /etc/apache2/sites-available/commandbridge.conf.old
sudo rm -f /etc/php/*/apache2/conf.d/90-commandbridge.ini.old /etc/php/*/cli/conf.d/90-commandbridge.ini.old
sudo rm -rf /etc/ssl/commandbridge
```
`/var/backups/commandbridge` holds the old update backups. Delete it once you no longer need them.

## Check-In Gateway: remove the old gateway, install the new one

The gateway only holds check-ins in transit and the check-in staff list (which OpenECC sends again), so nothing needs
to be copied.

**1. Switch off and move the old gateway.** If this server also had the main Command Bridge and you've already moved
`/etc/commandbridge`, skip that line.
```bash
sudo a2dissite commandbridge-gateway
sudo systemctl disable --now commandbridge-gateway-dispatch.timer 2>/dev/null
sudo rm -f /etc/systemd/system/commandbridge-gateway-dispatch.*
sudo systemctl daemon-reload
sudo systemctl reload apache2
sudo mv /etc/commandbridge /etc/commandbridge.old
sudo mv /var/www/commandbridge-gateway /var/www/commandbridge-gateway.old
sudo mv /etc/apache2/sites-available/commandbridge-gateway.conf /etc/apache2/sites-available/commandbridge-gateway.conf.old
```

**2. Install the new gateway:**
```bash
cd OpenECC/checkin-gateway
sudo ./install.sh
```
If you're changing its web address at the same time (for example to `checkin.example.ca`), give the new name here.

**3. Connect OpenECC to it:**
```bash
sudo /var/www/openecc/tools/setup_checkin.sh
```

**4. Later, remove the old pieces:**
```bash
sudo mariadb -e "DROP DATABASE commandbridge_gateway; DROP USER 'commandbridge_gateway'@'localhost'; DROP USER 'commandbridge_gateway'@'127.0.0.1';"
sudo rm -rf /etc/commandbridge.old /var/www/commandbridge-gateway.old /etc/apache2/sites-available/commandbridge-gateway.conf.old /etc/ssl/commandbridge-gateway
sudo rm -f /etc/php/*/apache2/conf.d/90-commandbridge-gateway.ini /etc/php/*/cli/conf.d/90-commandbridge-gateway.ini
```

## Changing the gateway's web address

If the gateway moves to a new name, QR codes and links printed with the old address stop working unless the old name
redirects. Keep the old DNS name pointing at the server and add an Apache site for it that redirects to the new one:

```apache
<VirtualHost *:80>
    ServerName checkin.old-name.example.ca
    Redirect permanent / https://checkin.example.ca/
</VirtualHost>
```
Add the matching `*:443` site with a certificate for the old name (`sudo certbot --apache -d checkin.old-name.example.ca`).
