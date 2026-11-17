#!/usr/bin/env bash
# Build an OpenECC appliance installer from the official Debian 13 netinst ISO.
#
#   ./openecc/appliance/build_iso.sh ~/Downloads/debian-13.7.0-amd64-netinst.iso
#
# Runs on any Linux with xorriso and openssl (Arch: sudo pacman -S --needed xorriso openssl).
# It does not need root. The result, openecc-appliance-<version>.iso, is written to the current folder.
#
# What the ISO does on the target machine (64-bit PC, UEFI or BIOS):
#   - installs Debian 13 with almost no questions (see preseed.cfg); needs a wired internet connection
#   - erases the first NVMe drive (it asks for "Yes" first; with no NVMe drive it asks which disk)
#   - switches on OpenECC setup mode: a Wi-Fi hotspot and a setup web page that installs OpenECC
set -Eeuo pipefail

die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
SRC_ISO="${1:-}"

[ -n "$SRC_ISO" ] || die "Give the Debian ISO, for example:  $0 ~/Downloads/debian-13.7.0-amd64-netinst.iso"
[ -f "$SRC_ISO" ] || die "File not found: $SRC_ISO"
for t in xorriso openssl md5sum tar; do command -v "$t" >/dev/null || die "'$t' is missing. On Arch: sudo pacman -S --needed xorriso openssl"; done
[ -f "$REPO/openecc/VERSION" ] || die "Run this from an OpenECC copy (openecc/VERSION not found under $REPO)."

VERSION="$(tr -d '[:space:]' < "$REPO/openecc/VERSION")"
OUT_ISO="$PWD/openecc-appliance-$VERSION.iso"

say "OpenECC appliance builder ($VERSION)"
say "Debian ISO : $SRC_ISO"
say "Result     : $OUT_ISO"
say ""

# ------------------------------------------------------------------ check the source ISO
xorriso -indev "$SRC_ISO" -ls /boot/grub/grub.cfg >/dev/null 2>&1 || die "This doesn't look like a Debian installer ISO (no /boot/grub/grub.cfg)."
xorriso -indev "$SRC_ISO" -ls /install.amd/vmlinuz >/dev/null 2>&1 || die "This doesn't look like a 64-bit PC (amd64) Debian ISO (no /install.amd)."

# ------------------------------------------------------------------ questions
rand_code()  { local s=""; while [ "${#s}" -lt "$1" ]; do s="$s$(openssl rand -hex 16 | tr -dc '0-9')"; done; printf '%s' "${s:0:$1}"; }
rand_words() { local s=""; while [ "${#s}" -lt 12 ]; do s="$s$(openssl rand -base64 24 | tr -dc 'abcdefghjkmnpqrstuvwxyz23456789')"; done; printf '%s' "${s:0:12}"; }
ask() {  # ask VAR "question" "default"
    local reply; read -r -p "$2 [$3]: " reply; printf -v "$1" '%s' "${reply:-$3}"
}

say "Answer a few questions (press Enter to accept the value in [brackets])."
say ""
ask DESKTOP_YN "Include a desktop (auto-login and browser when a screen is plugged in)? y/n" "y"
case "$DESKTOP_YN" in y|Y|yes) DESKTOP=1 ;; n|N|no) DESKTOP=0 ;; *) die "Answer y or n." ;; esac

ask TIMEZONE "Time zone" "America/Edmonton"
[[ $TIMEZONE =~ ^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$ ]] || die "That doesn't look like a time zone (for example America/Edmonton)."

ask WIFI_SSID "Wi-Fi network name for setup" "OpenECC-Setup"
[[ $WIFI_SSID =~ ^[A-Za-z0-9._\ -]{1,32}$ ]] || die "Wi-Fi name: 1 to 32 letters, digits, spaces, dots, dashes or underscores."
DEF_PSK="$(rand_words)"
ask WIFI_PSK "Wi-Fi password for setup (8 or more characters)" "$DEF_PSK"
[ "${#WIFI_PSK}" -ge 8 ] && [ "${#WIFI_PSK}" -le 63 ] || die "Wi-Fi password: 8 to 63 characters."
[[ $WIFI_PSK =~ ^[[:print:]]+$ ]] || die "Wi-Fi password: printable characters only."

DEF_CODE="$(rand_code 8)"
ask SETUP_CODE "Setup code (asked on the setup page)" "$DEF_CODE"
[[ $SETUP_CODE =~ ^[A-Za-z0-9]{6,32}$ ]] || die "Setup code: 6 to 32 letters or digits."

say ""
say "Password for the computer's own login (user 'openecc', for the desktop, SSH and sudo)."
while :; do
    read -r -s -p "Password: " PW1; echo
    read -r -s -p "Again:    " PW2; echo
    [ "$PW1" = "$PW2" ] && [ "${#PW1}" -ge 8 ] && break
    say "They differ or are shorter than 8 characters. Try again."
done
USER_HASH="$(printf '%s' "$PW1" | openssl passwd -6 -stdin)"
unset PW1 PW2

# ------------------------------------------------------------------ work folder
WORK="$(mktemp -d "${TMPDIR:-/tmp}/openecc-iso.XXXXXX")"
trap 'chmod -R u+w "$WORK" 2>/dev/null; rm -rf "$WORK"' EXIT
mkdir -p "$WORK/payload" "$WORK/boot"

extract() {  # extract /path/in/iso localfile  (missing files are skipped)
    xorriso -osirrox on -indev "$SRC_ISO" -extract "$1" "$2" >/dev/null 2>&1 || return 1
    chmod u+w "$2"
}

say ""
say "Building..."

# ------------------------------------------------------------------ the OpenECC files (from this copy)
tar -C "$REPO" --exclude=.git --exclude='*.iso' --exclude='*.zip' -cf - openecc LICENSE.txt README.md \
    | tar -x -C "$WORK/payload"
if command -v git >/dev/null && git -C "$REPO" rev-parse --git-dir >/dev/null 2>&1; then
    [ -z "$(git -C "$REPO" status --porcelain -- openecc 2>/dev/null)" ] \
        || say "  Note: this copy has changes not committed to git. They are included as they are."
    git -C "$REPO" rev-parse --short HEAD > "$WORK/payload/BUILD_COMMIT" 2>/dev/null || true
fi
{
    echo "# OpenECC appliance settings from build_iso.sh. setup_mode.sh reads and then deletes this file."
    printf 'DESKTOP=%s\n' "$DESKTOP"
    printf 'TIMEZONE=%q\n' "$TIMEZONE"
    printf 'WIFI_SSID=%q\n' "$WIFI_SSID"
    printf 'WIFI_PSK=%q\n' "$WIFI_PSK"
    printf 'SETUP_CODE=%q\n' "$SETUP_CODE"
} > "$WORK/payload/appliance.conf"

# ------------------------------------------------------------------ preseed
if [ "$DESKTOP" = 1 ]; then
    TASKS="standard, ssh-server, desktop, xfce-desktop"
    PKGS="network-manager dnsmasq-base iw wireless-regdb ssl-cert sudo curl rsync firefox-esr"
else
    TASKS="standard, ssh-server"
    PKGS="network-manager dnsmasq-base iw wireless-regdb ssl-cert sudo curl rsync"
fi
sed -e "s|@USER_PASSWORD_HASH@|$USER_HASH|" -e "s|@TIMEZONE@|$TIMEZONE|" \
    -e "s|@TASKS@|$TASKS|" -e "s|@PACKAGES@|$PKGS|" \
    "$HERE/preseed.cfg" > "$WORK/preseed.cfg"
grep -q '@[A-Z_]*@' "$WORK/preseed.cfg" && die "preseed.cfg still has an unfilled @value@."

# ------------------------------------------------------------------ boot menus
BOOT_ARGS="auto=true priority=high preseed/file=/cdrom/preseed.cfg locale=en_CA.UTF-8 keymap=us"

# UEFI (GRUB): add our entry first and start it after 10 seconds
extract /boot/grub/grub.cfg "$WORK/boot/grub.cfg" || die "Could not read /boot/grub/grub.cfg from the ISO."
awk -v args="$BOOT_ARGS" '
    !done && /^menuentry/ {
        print "set default=0"
        print "set timeout=10"
        print "menuentry --hotkey=o '\''Install OpenECC appliance (erases the NVMe drive)'\'' {"
        print "    set background_color=black"
        print "    linux    /install.amd/vmlinuz vga=788 " args " --- quiet"
        print "    initrd   /install.amd/initrd.gz"
        print "}"
        done = 1
    }
    { print }' "$WORK/boot/grub.cfg" > "$WORK/boot/grub.cfg.new"
grep -q "Install OpenECC appliance" "$WORK/boot/grub.cfg.new" || die "Could not add the OpenECC entry to the GRUB menu."
mv "$WORK/boot/grub.cfg.new" "$WORK/boot/grub.cfg"
MAPS=(-map "$WORK/boot/grub.cfg" /boot/grub/grub.cfg)

# Older BIOS machines (isolinux): add our entry as the default, best effort
BIOS_OK=0
if extract /isolinux/txt.cfg "$WORK/boot/txt.cfg" && extract /isolinux/isolinux.cfg "$WORK/boot/isolinux.cfg"; then
    {
        printf 'label openecc\n\tmenu label ^OpenECC appliance install (erases the NVMe drive)\n\tmenu default\n'
        printf '\tkernel /install.amd/vmlinuz\n\tappend vga=788 initrd=/install.amd/initrd.gz %s --- quiet\n' "$BOOT_ARGS"
        sed '/menu default/d' "$WORK/boot/txt.cfg"
    } > "$WORK/boot/txt.cfg.new" && mv "$WORK/boot/txt.cfg.new" "$WORK/boot/txt.cfg"
    sed -i 's/^timeout .*/timeout 100/' "$WORK/boot/isolinux.cfg"
    grep -q '^timeout' "$WORK/boot/isolinux.cfg" || echo 'timeout 100' >> "$WORK/boot/isolinux.cfg"
    MAPS+=(-map "$WORK/boot/txt.cfg" /isolinux/txt.cfg -map "$WORK/boot/isolinux.cfg" /isolinux/isolinux.cfg)
    if extract /isolinux/gtk.cfg "$WORK/boot/gtk.cfg"; then
        sed -i '/menu default/d' "$WORK/boot/gtk.cfg"
        MAPS+=(-map "$WORK/boot/gtk.cfg" /isolinux/gtk.cfg)
    fi
    BIOS_OK=1
fi

# ------------------------------------------------------------------ checksum list on the ISO
if extract /md5sum.txt "$WORK/md5sum.txt"; then
    grep -v -e ' \./boot/grub/grub\.cfg$' -e ' \./isolinux/' -e ' \./preseed\.cfg$' -e ' \./openecc/' "$WORK/md5sum.txt" > "$WORK/md5sum.new" || true
    {
        (cd "$WORK/boot" && md5sum grub.cfg | sed 's|  grub.cfg|  ./boot/grub/grub.cfg|')
        if [ "$BIOS_OK" = 1 ]; then
            (cd "$WORK/boot" && for f in txt.cfg isolinux.cfg gtk.cfg; do [ -f "$f" ] && md5sum "$f" | sed "s|  $f|  ./isolinux/$f|"; done; true)
        fi
        (cd "$WORK" && md5sum preseed.cfg | sed 's|  preseed.cfg|  ./preseed.cfg|')
        (cd "$WORK/payload" && find . -type f -print0 | sort -z | xargs -0 md5sum | sed 's|  \./|  ./openecc/|')
    } >> "$WORK/md5sum.new"
    MAPS+=(-map "$WORK/md5sum.new" /md5sum.txt)
fi

# ------------------------------------------------------------------ write the new ISO
rm -f "$OUT_ISO"
xorriso -indev "$SRC_ISO" -outdev "$OUT_ISO" \
    "${MAPS[@]}" \
    -map "$WORK/preseed.cfg" /preseed.cfg \
    -map "$WORK/payload" /openecc \
    -boot_image any replay >"$WORK/xorriso.log" 2>&1 || { tail -20 "$WORK/xorriso.log" >&2; die "xorriso could not write the ISO."; }

SIZE="$(du -h "$OUT_ISO" | cut -f1)"
cat <<EOF

==============================================
OpenECC appliance installer built ($SIZE)
  $OUT_ISO

Write these on a label for the box:
  Wi-Fi network : $WIFI_SSID
  Wi-Fi password: $WIFI_PSK
  Setup page    : https://10.42.0.1
  Setup code    : $SETUP_CODE
  Computer login: openecc  (the password you typed)

The setup page makes you choose a new Wi-Fi password.
$( [ "$BIOS_OK" = 1 ] || echo "Note: no BIOS (isolinux) menu found; the ISO boots the OpenECC entry on UEFI machines only." )

Put it on a USB stick (replace sdX with the stick; lsblk shows it; THIS ERASES THE STICK):
  sudo dd if="$OUT_ISO" of=/dev/sdX bs=4M status=progress oflag=sync
==============================================
EOF
