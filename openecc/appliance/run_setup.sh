#!/usr/bin/env bash
# OpenECC setup mode: the root helper. Installed as /usr/local/sbin/openecc-setup-run by setup_mode.sh.
#
# The setup page (running as www-data) never runs anything as root. It writes the answers to
# /var/lib/openecc-setup/request.json; openecc-setup.path notices the file and starts this script, which:
#   1. takes the file (so the passwords in it are only on disk for a moment) and checks every value
#   2. sets the clock and time zone if asked
#   3. runs the normal install.sh unattended, writing progress to progress.log for the page
#   4. on success, schedules "--finish" 20 seconds later (so the page can show "done"), which switches
#      setup mode off for good and changes the Wi-Fi name and password
set -Eeuo pipefail

STATE_DIR=/var/lib/openecc-setup
CONF=/etc/openecc-setup/setup.conf
REQ="$STATE_DIR/request.json"
LOG="$STATE_DIR/progress.log"
PENDING_WIFI=/etc/openecc-setup/wifi.pending
INSTALL_DIR=/var/www/openecc

[ "$(id -u)" -eq 0 ] || { echo "Run as root." >&2; exit 1; }
conf() { sed -n "s/^$1=//p" "$CONF" 2>/dev/null | tail -1; }
set_state() { echo "$1" > "$STATE_DIR/state"; chown root:www-data "$STATE_DIR/state"; chmod 0644 "$STATE_DIR/state"; }
log() { printf '%s\n' "$*" >> "$LOG"; }

# ------------------------------------------------------------------ --finish: leave setup mode
if [ "${1:-}" = --finish ]; then
    systemctl disable --now openecc-setup.path >/dev/null 2>&1 || true
    a2dissite -q openecc-setup >/dev/null 2>&1 || true
    rm -f /etc/apache2/sites-available/openecc-setup.conf
    rm -rf /var/www/openecc-setup
    systemctl reload apache2 || true
    if [ -f "$PENDING_WIFI" ]; then
        mapfile -t W < "$PENDING_WIFI"
        if [ -n "${W[0]:-}" ] && [ -n "${W[1]:-}" ]; then
            nmcli connection modify OpenECC-Hotspot 802-11-wireless.ssid "${W[0]}" wifi-sec.psk "${W[1]}" || true
            nmcli connection up OpenECC-Hotspot >/dev/null 2>&1 || true
            NOTE="$(getent passwd openecc | cut -d: -f6 || true)/Desktop/OpenECC Wi-Fi.txt"
            if [ -f "$NOTE" ]; then
                printf 'OpenECC\n\nWi-Fi network : %s\nWi-Fi password: %s\nOpenECC       : https://%s\n' \
                    "${W[0]}" "${W[1]}" "$(conf HOTSPOT_IP)" > "$NOTE"
            fi
        fi
        shred -u "$PENDING_WIFI" 2>/dev/null || rm -f "$PENDING_WIFI"
    fi
    rm -f "$CONF"                       # the setup code is no longer needed
    set_state done
    exit 0
fi

# ------------------------------------------------------------------ take the answers
[ -f "$REQ" ] || exit 0
[ "$(cat "$STATE_DIR/state" 2>/dev/null)" = done ] && { rm -f "$REQ"; exit 0; }
WORK="$(mktemp /run/openecc-setup.XXXXXX)"; chmod 0600 "$WORK"
cat "$REQ" > "$WORK"; shred -u "$REQ" 2>/dev/null || rm -f "$REQ"
trap 'shred -u "$WORK" 2>/dev/null || rm -f "$WORK"' EXIT

: > "$LOG"; chown root:www-data "$LOG"; chmod 0640 "$LOG"
set_state running
fail() { log ""; log "SETUP FAILED: $*"; set_state failed; exit 1; }

FIELDS=(timezone server_name admin_user admin_name admin_email admin_password wifi_ssid wifi_psk set_epoch)
mapfile -d '' V < <(php -r '
    $j = json_decode((string)file_get_contents($argv[1]), true);
    if (!is_array($j)) { exit(1); }
    foreach (array_slice($argv, 2) as $k) { $v = $j[$k] ?? ""; echo is_scalar($v) ? (string)$v : "", "\0"; }
' "$WORK" "${FIELDS[@]}") || fail "The answers could not be read."
[ "${#V[@]}" -eq "${#FIELDS[@]}" ] || fail "The answers could not be read."
TZ_NAME="${V[0]}"; SERVER_NAME="${V[1]}"; ADMIN_USER="${V[2]}"; ADMIN_NAME="${V[3]}"; ADMIN_EMAIL="${V[4]}"
ADMIN_PASSWORD="${V[5]}"; WIFI_SSID="${V[6]}"; WIFI_PSK="${V[7]}"; SET_EPOCH="${V[8]}"

# Check everything again here; the page's own checks are not trusted.
[[ $TZ_NAME =~ ^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$ ]] && [ -e "/usr/share/zoneinfo/$TZ_NAME" ] || fail "Unknown time zone."
[[ $SERVER_NAME =~ ^[A-Za-z0-9.-]{1,253}$ ]] || fail "The server name may only use letters, digits, dots and hyphens."
[[ $ADMIN_USER =~ ^[A-Za-z0-9._-]{3,50}$ ]] || fail "The admin username must be 3 to 50 letters, digits, dots, dashes or underscores."
[[ $ADMIN_NAME =~ ^[^[:cntrl:]]{1,100}$ ]] || fail "The admin's full name is missing or too long."
[ -z "$ADMIN_EMAIL" ] || [[ $ADMIN_EMAIL =~ ^[^[:space:][:cntrl:]@]+@[^[:space:][:cntrl:]@]+$ ]] || fail "The email address is not valid."
[ "${#ADMIN_PASSWORD}" -ge 12 ] && [[ $ADMIN_PASSWORD =~ ^[^[:cntrl:]]+$ ]] || fail "The admin password must be at least 12 characters."
[[ $WIFI_SSID =~ ^[A-Za-z0-9._\ -]{1,32}$ ]] || fail "The Wi-Fi name must be 1 to 32 letters, digits, spaces, dots, dashes or underscores."
[ "${#WIFI_PSK}" -ge 8 ] && [ "${#WIFI_PSK}" -le 63 ] && [[ $WIFI_PSK =~ ^[[:print:]]+$ ]] || fail "The Wi-Fi password must be 8 to 63 characters."
[ -z "$SET_EPOCH" ] || [[ $SET_EPOCH =~ ^[0-9]{10}$ ]] || fail "The clock time is not valid."

SRC_DIR="$(conf SRC_DIR)"
[ -n "$SRC_DIR" ] && [ -f "$SRC_DIR/install.sh" ] || fail "The OpenECC files were not found (SRC_DIR in $CONF)."
[ -f /etc/openecc/install.conf ] && fail "OpenECC is already installed on this machine."

# ------------------------------------------------------------------ clock and time zone
if [ -n "$SET_EPOCH" ]; then
    log "Setting the clock from your device: $(date -d "@$SET_EPOCH" -Is)"
    date -s "@$SET_EPOCH" >/dev/null
    hwclock --systohc >/dev/null 2>&1 || log "  (could not save the time to the hardware clock)"
fi
timedatectl set-timezone "$TZ_NAME" 2>/dev/null || ln -sf "/usr/share/zoneinfo/$TZ_NAME" /etc/localtime
log "Time zone: $TZ_NAME"

# ------------------------------------------------------------------ install OpenECC (the normal installer, unattended)
log "Installing OpenECC..."
log ""
set +e
(
    cd "$SRC_DIR" || exit 1
    export CB_ASSUME_YES=1 CB_PLAIN=1 CB_SKIP_PACKAGES=1 CB_CHECKIN=0 CB_TLS_MODE=2
    export CB_INSTALL_DIR="$INSTALL_DIR" CB_SERVER_NAME="$SERVER_NAME" CB_TIMEZONE="$TZ_NAME"
    export CB_ADMIN_USER="$ADMIN_USER" CB_ADMIN_NAME="$ADMIN_NAME" CB_ADMIN_EMAIL="$ADMIN_EMAIL" CB_ADMIN_PASSWORD="$ADMIN_PASSWORD"
    bash ./install.sh
) >> "$LOG" 2>&1 < /dev/null
RC=$?
set -e
unset ADMIN_PASSWORD
[ "$RC" -eq 0 ] || fail "install.sh stopped with an error (exit code $RC). The messages above say why."

# ------------------------------------------------------------------ done: hand over to OpenECC
umask 077
printf '%s\n%s\n' "$WIFI_SSID" "$WIFI_PSK" > "$PENDING_WIFI"
umask 022
printf '{"url":"https://%s/","wifi_ssid":"%s"}\n' "$SERVER_NAME" "$WIFI_SSID" > "$STATE_DIR/result.json"
chown root:www-data "$STATE_DIR/result.json"; chmod 0640 "$STATE_DIR/result.json"
log ""
log "OpenECC is installed. Setup mode switches off in 20 seconds and the Wi-Fi changes to \"$WIFI_SSID\"."
set_state installed
systemd-run --quiet --unit=openecc-setup-finish --on-active=20 /usr/local/sbin/openecc-setup-run --finish \
    || { sleep 20; /usr/local/sbin/openecc-setup-run --finish; }
