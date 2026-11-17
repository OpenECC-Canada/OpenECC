#!/usr/bin/env bash
# Put this Debian 13 machine into OpenECC "setup mode":
#   - installs every package OpenECC needs (so the later install works with no internet)
#   - a Wi-Fi hotspot at 10.42.0.1 (NetworkManager "shared" mode hands out addresses)
#   - a setup web page (https://10.42.0.1, and http://localhost on the machine itself) that asks the
#     install questions; a root helper (openecc-setup.path) then runs install.sh with the answers
#   - with a desktop: auto-login and a browser that opens the setup page
#
# Run as root. The Debian installer runs it at the end (preseed late_command, --installer).
# On a Debian 13 machine you set up yourself:  sudo bash openecc/appliance/setup_mode.sh
# Settings come from appliance.conf (written by build_iso.sh) next to the openecc folder, or from
# the environment: WIFI_SSID, WIFI_PSK, SETUP_CODE, TIMEZONE, DESKTOP=1|0.
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$(cd "$HERE/.." && pwd)"          # .../openecc
SRC_ROOT="$(cd "$SRC_DIR/.." && pwd)"      # .../ (holds appliance.conf from the ISO)
INSTALLER=0; [ "${1:-}" = --installer ] && INSTALLER=1

STATE_DIR=/var/lib/openecc-setup
CONF_DIR=/etc/openecc-setup
WEB_DIR=/var/www/openecc-setup
APP_USER=openecc
HOTSPOT_IP=10.42.0.1
LOG=/var/log/openecc-setup-mode.log

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || die "Run as root (sudo bash $0)."
exec > >(tee -a "$LOG") 2>&1
say "== OpenECC setup mode ($(date -Is)) =="

# ------------------------------------------------------------------ settings
if [ -f "$SRC_ROOT/appliance.conf" ]; then
    # shellcheck disable=SC1091
    . "$SRC_ROOT/appliance.conf"
fi
rand_code() { local s=""; while [ "${#s}" -lt "$1" ]; do s="$s$(openssl rand -hex 16 | tr -dc '0-9')"; done; printf '%s' "${s:0:$1}"; }
WIFI_SSID="${WIFI_SSID:-OpenECC-Setup}"
WIFI_PSK="${WIFI_PSK:-}"
SETUP_CODE="${SETUP_CODE:-}"
TIMEZONE="${TIMEZONE:-America/Edmonton}"
DESKTOP="${DESKTOP:-auto}"
GENERATED=0
[ -n "$WIFI_PSK" ] || { WIFI_PSK="$(openssl rand -base64 24 | tr -dc 'a-km-z2-9' | cut -c1-12)"; GENERATED=1; }
[ -n "$SETUP_CODE" ] || { SETUP_CODE="$(rand_code 8)"; GENERATED=1; }
[ "${#WIFI_PSK}" -ge 8 ] || die "WIFI_PSK must be at least 8 characters."
if [ "$DESKTOP" = auto ]; then
    if dpkg-query -W -f='${Status}' lightdm 2>/dev/null | grep -q 'install ok installed'; then DESKTOP=1; else DESKTOP=0; fi
fi

# ------------------------------------------------------------------ packages (internet needed here, not later)
# During the Debian install, stop services from starting inside the installer's chroot.
if [ "$INSTALLER" = 1 ] && [ ! -e /usr/sbin/policy-rc.d ]; then
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; chmod 0755 /usr/sbin/policy-rc.d
    trap 'rm -f /usr/sbin/policy-rc.d' EXIT
fi
# shellcheck disable=SC1091
. "$SRC_DIR/deploy/lib/packages.sh"
# shellcheck disable=SC1091
. "$SRC_DIR/deploy/lib/timesync.sh"
EXTRA=(network-manager dnsmasq-base iw wireless-regdb ssl-cert sudo curl whiptail)
TS="$(timesync_packages)"; [ -n "$TS" ] && EXTRA+=("$TS")
[ "$DESKTOP" = 1 ] && EXTRA+=(firefox-esr)
say "Installing packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends "${OPENECC_PACKAGES[@]}" "${EXTRA[@]}"

# ------------------------------------------------------------------ the root helper and its trigger
say "Installing the setup helper..."
install -m 0750 -o root -g root "$HERE/run_setup.sh" /usr/local/sbin/openecc-setup-run
install -d -m 0750 -o root -g www-data "$CONF_DIR"
install -d -m 0770 -o root -g www-data "$STATE_DIR"
CODE_HASH="$(SETUP_CODE="$SETUP_CODE" php -r 'echo password_hash(getenv("SETUP_CODE"), PASSWORD_DEFAULT);')"
{
    echo "# OpenECC setup mode. Read by the setup page (www-data) and openecc-setup-run (root)."
    printf 'SRC_DIR=%s\n' "$SRC_DIR"
    printf 'SETUP_CODE_HASH=%s\n' "$CODE_HASH"
    printf 'WIFI_SSID=%s\n' "$WIFI_SSID"
    printf 'TIMEZONE=%s\n' "$TIMEZONE"
    printf 'HOTSPOT_IP=%s\n' "$HOTSPOT_IP"
} > "$CONF_DIR/setup.conf"
chown root:www-data "$CONF_DIR/setup.conf"; chmod 0640 "$CONF_DIR/setup.conf"
echo waiting > "$STATE_DIR/state"; chown root:www-data "$STATE_DIR/state"; chmod 0644 "$STATE_DIR/state"

cat > /etc/systemd/system/openecc-setup.path <<'EOF'
[Unit]
Description=OpenECC setup: wait for answers from the setup page

[Path]
PathExists=/var/lib/openecc-setup/request.json
Unit=openecc-setup.service

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/openecc-setup.service <<'EOF'
[Unit]
Description=OpenECC setup: install OpenECC with the answers from the setup page
After=network.target mariadb.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/openecc-setup-run
TimeoutStartSec=30min
EOF
systemctl enable openecc-setup.path >/dev/null 2>&1 || ln -sf /etc/systemd/system/openecc-setup.path /etc/systemd/system/multi-user.target.wants/openecc-setup.path
systemctl enable apache2 mariadb NetworkManager >/dev/null 2>&1 || true

# ------------------------------------------------------------------ the setup web page
say "Installing the setup page..."
rm -rf "$WEB_DIR"
install -d -m 0750 -o root -g www-data "$WEB_DIR"
cp -r "$HERE/setup-web/." "$WEB_DIR/"
cp "$SRC_DIR/app/assets/img/openecc-icon.svg" "$WEB_DIR/openecc-icon.svg"
chown -R root:www-data "$WEB_DIR"
find "$WEB_DIR" -type d -exec chmod 0750 {} +
find "$WEB_DIR" -type f -exec chmod 0640 {} +

cat > /etc/apache2/sites-available/openecc-setup.conf <<EOF
# OpenECC setup mode (removed when setup finishes). On the machine itself (localhost) it is served
# over plain HTTP; everyone else is sent to HTTPS (self-signed "snakeoil" certificate).
<VirtualHost *:80>
    DocumentRoot $WEB_DIR
    RewriteEngine On
    RewriteCond %{REMOTE_ADDR} !^127\.
    RewriteCond %{REMOTE_ADDR} !^::1\$
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=302,L]
</VirtualHost>
<VirtualHost *:443>
    DocumentRoot $WEB_DIR
    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile /etc/ssl/private/ssl-cert-snakeoil.key
</VirtualHost>
<Directory $WEB_DIR>
    Require all granted
    AllowOverride None
    Options -Indexes
    DirectoryIndex index.php
    Header always set X-Frame-Options DENY
    Header always set X-Content-Type-Options nosniff
    Header always set Cache-Control "no-store"
</Directory>
EOF
a2enmod -q ssl rewrite headers >/dev/null
a2dissite -q 000-default default-ssl >/dev/null 2>&1 || true
a2ensite -q openecc-setup >/dev/null
[ -f /etc/ssl/certs/ssl-cert-snakeoil.pem ] || make-ssl-cert generate-default-snakeoil --force-overwrite

# ------------------------------------------------------------------ Wi-Fi hotspot
say "Setting up the Wi-Fi hotspot..."
# Canadian Wi-Fi rules, so the hotspot can use the right channels
echo 'options cfg80211 ieee80211_regdom=CA' > /etc/modprobe.d/openecc-wifi-region.conf
install -d -m 0700 /etc/NetworkManager/system-connections
umask 077
cat > /etc/NetworkManager/system-connections/OpenECC-Hotspot.nmconnection <<EOF
[connection]
id=OpenECC-Hotspot
type=wifi
autoconnect=true
autoconnect-priority=100

[wifi]
mode=ap
ssid=$WIFI_SSID
band=bg

[wifi-security]
key-mgmt=wpa-psk
proto=rsn
pairwise=ccmp
group=ccmp
psk=$WIFI_PSK

[ipv4]
method=shared
address1=$HOTSPOT_IP/24

[ipv6]
method=disabled
EOF
umask 022
chmod 0600 /etc/NetworkManager/system-connections/OpenECC-Hotspot.nmconnection
# Wi-Fi interfaces listed in /etc/network/interfaces would be hidden from NetworkManager
if [ -f /etc/network/interfaces ] && grep -qE '^\s*(auto|allow-hotplug|iface)\s+wl' /etc/network/interfaces; then
    cp /etc/network/interfaces /etc/network/interfaces.before-openecc
    sed -i -E '/^\s*(auto|allow-hotplug)\s+wl/d; /^\s*iface\s+wl/,/^\s*$/d' /etc/network/interfaces
fi

# ------------------------------------------------------------------ the console login screen says where to go
if ! grep -q 'OpenECC' /etc/issue 2>/dev/null; then
    cat >> /etc/issue <<EOF

  OpenECC setup: join the Wi-Fi network "$WIFI_SSID" and open https://$HOTSPOT_IP
  (or, on a wired network, https://\4)

EOF
fi

# ------------------------------------------------------------------ desktop: auto-login and open the setup page
if [ "$DESKTOP" = 1 ] && id "$APP_USER" >/dev/null 2>&1; then
    say "Setting up desktop auto-login..."
    groupadd -f autologin
    usermod -aG autologin "$APP_USER"
    install -d /etc/lightdm/lightdm.conf.d
    cat > /etc/lightdm/lightdm.conf.d/50-openecc-autologin.conf <<EOF
[Seat:*]
autologin-user=$APP_USER
autologin-user-timeout=0
EOF
    cat > /usr/local/bin/openecc-open <<'EOF'
#!/bin/sh
# Opens OpenECC (or its setup page while setup mode is on) in the browser after the desktop starts.
sleep 6
if [ "$(cat /var/lib/openecc-setup/state 2>/dev/null)" = done ] || [ ! -d /var/www/openecc-setup ]; then
    URL=https://localhost/
else
    URL=http://localhost/
fi
exec firefox-esr --new-window "$URL"
EOF
    chmod 0755 /usr/local/bin/openecc-open
    cat > /etc/xdg/autostart/openecc-open.desktop <<'EOF'
[Desktop Entry]
Type=Application
Name=OpenECC
Comment=Open OpenECC in the browser
Exec=/usr/local/bin/openecc-open
Icon=web-browser
X-GNOME-Autostart-enabled=true
EOF
    HOME_DIR="$(getent passwd "$APP_USER" | cut -d: -f6)"
    if [ -n "$HOME_DIR" ] && [ -d "$HOME_DIR" ]; then
        install -d -o "$APP_USER" -g "$APP_USER" "$HOME_DIR/Desktop"
        cat > "$HOME_DIR/Desktop/OpenECC Wi-Fi.txt" <<EOF
OpenECC setup

Wi-Fi network : $WIFI_SSID
Wi-Fi password: $WIFI_PSK
Setup page    : https://$HOTSPOT_IP  (from another device on this Wi-Fi)
On this screen: http://localhost

The setup page asks you to choose a new Wi-Fi password. Delete this file afterwards.
EOF
        chown "$APP_USER:$APP_USER" "$HOME_DIR/Desktop/OpenECC Wi-Fi.txt"; chmod 0600 "$HOME_DIR/Desktop/OpenECC Wi-Fi.txt"
    fi
fi

# ------------------------------------------------------------------ tidy up
rm -f "$SRC_ROOT/appliance.conf"     # held the Wi-Fi password and setup code in plain text
[ "$INSTALLER" = 1 ] && { chown -R root:root "$SRC_ROOT"; chmod -R go-w "$SRC_ROOT"; }

say ""
say "Setup mode is on. After the next restart:"
say "  Wi-Fi network : $WIFI_SSID"
[ "$GENERATED" = 1 ] && say "  Wi-Fi password: $WIFI_PSK" && say "  Setup code    : $SETUP_CODE"
say "  Setup page    : https://$HOTSPOT_IP"
[ "$INSTALLER" = 1 ] || say "Restart now (sudo reboot), or start the hotspot with: sudo nmcli connection reload && sudo nmcli connection up OpenECC-Hotspot"
