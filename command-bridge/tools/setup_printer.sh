#!/usr/bin/env bash
# Adds a network printer to the Command Bridge server so people can print forms from the app (Settings > Printers).
# Printing goes straight to the printer's IP address. Run as root on the Command Bridge server:
#
#   sudo ./setup_printer.sh add NAME IP_ADDRESS     add a printer (driverless first, generic PostScript fallback)
#   sudo ./setup_printer.sh add NAME IP --postscript  skip driverless and use generic PostScript
#   sudo ./setup_printer.sh list                    list the printers and their status
#   sudo ./setup_printer.sh test NAME               print a test page
#   sudo ./setup_printer.sh remove NAME             remove a printer
#
# NAME: letters, numbers, dash and underscore (e.g. ECC-Copier). Installs the print system (CUPS) if it is missing.
set -Eeuo pipefail

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }
usage() { sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

[ "$(id -u)" -eq 0 ] || die "Run as root (sudo $0 ...)."
cmd="${1:-}"; [ -n "$cmd" ] || usage

valid_name() { [[ "$1" =~ ^[A-Za-z0-9_-]{1,40}$ ]] || die "Printer name '$1': use letters, numbers, dash or underscore (max 40)."; }
valid_host() { [[ "$1" =~ ^[A-Za-z0-9.:-]{1,253}$ ]] || die "'$1' does not look like an IP address or host name."; }

ensure_cups() {
    if command -v lpadmin >/dev/null 2>&1 && command -v lp >/dev/null 2>&1; then
        systemctl enable --now cups >/dev/null 2>&1 || true
        return 0
    fi
    say "Installing the print system (CUPS)..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq cups cups-client cups-filters >/dev/null
    systemctl enable --now cups >/dev/null 2>&1 || true
    command -v lpadmin >/dev/null 2>&1 || die "CUPS did not install. Check the network and apt sources, then try again."
}

port_open() {  # port_open HOST PORT
    timeout 4 bash -c "exec 3<>/dev/tcp/$1/$2" 2>/dev/null
}

test_page() {
    local name="$1" file=/usr/share/cups/data/testprint
    [ -f "$file" ] || file=/usr/share/cups/data/default-testpage.pdf
    [ -f "$file" ] || die "No CUPS test page found; print a form from Command Bridge instead."
    lp -d "$name" -t "Command Bridge test page" "$file"
}

case "$cmd" in
    add)
        name="${2:-}"; host="${3:-}"; mode="${4:-}"
        [ -n "$name" ] && [ -n "$host" ] || usage
        valid_name "$name"; valid_host "$host"
        ensure_cups
        if lpstat -p "$name" >/dev/null 2>&1; then die "A printer called $name already exists. Remove it first, or pick another name."; fi
        added=""
        if [ "$mode" != "--postscript" ]; then
            if port_open "$host" 631; then
                say "Trying driverless printing (IPP Everywhere) at $host..."
                for uri in "ipp://$host/ipp/print" "ipp://$host:631/ipp/print" "ipp://$host/ipp"; do
                    if lpadmin -p "$name" -E -v "$uri" -m everywhere >/dev/null 2>&1; then added="driverless ($uri)"; break; fi
                done
            else
                say "Port 631 (IPP) is not open on $host; driverless printing is not available."
            fi
        fi
        if [ -z "$added" ]; then
            port_open "$host" 9100 || die "Cannot reach $host on port 631 or 9100. Check the address, and that the server can reach the printer."
            ppd="$(lpinfo -m 2>/dev/null | awk '/[Gg]eneric [Pp]ost[Ss]cript/ {print $1; exit}')"
            [ -n "$ppd" ] || ppd="drv:///sample.drv/generic.ppd"
            say "Using generic PostScript at socket://$host:9100 ..."
            lpadmin -p "$name" -E -v "socket://$host:9100" -m "$ppd" || die "The printer could not be added."
            added="generic PostScript (socket://$host:9100)"
        fi
        # Letter paper, and fit each page to it (landscape forms print the right way round).
        lpadmin -p "$name" -o media-default=Letter -o PageSize=Letter -o fit-to-page-default=true >/dev/null 2>&1 || true
        cupsenable "$name" >/dev/null 2>&1 || true
        cupsaccept "$name" >/dev/null 2>&1 || true
        say "Added $name: $added"
        say "Printing a test page..."
        test_page "$name" || say "The test page was not accepted. Check the printer, then: sudo $0 test $name"
        say ""
        say "Done. In Command Bridge open Settings > Printers to give it a friendly name."
        ;;
    list)
        if ! command -v lpstat >/dev/null 2>&1; then say "The print system (CUPS) is not installed. Add a printer to install it."; exit 0; fi
        lpstat -v 2>/dev/null || true
        lpstat -p 2>/dev/null || say "No printers."
        ;;
    test)
        name="${2:-}"; [ -n "$name" ] || usage; valid_name "$name"
        lpstat -p "$name" >/dev/null 2>&1 || die "No printer called $name."
        test_page "$name"
        ;;
    remove)
        name="${2:-}"; [ -n "$name" ] || usage; valid_name "$name"
        lpstat -p "$name" >/dev/null 2>&1 || die "No printer called $name."
        lpadmin -x "$name"
        say "Removed $name."
        ;;
    *) usage ;;
esac
