#!/usr/bin/env bash
# Set up network printers so people can print forms from Command Bridge (Settings > Printers). Printing goes straight
# to each printer's IP address. Run as root on the Command Bridge server:
#
#     sudo <install folder>/tools/setup_printer.sh                         menu: add, list, test or remove printers
#     sudo <install folder>/tools/setup_printer.sh add NAME IP [--postscript]
#     sudo <install folder>/tools/setup_printer.sh list | test NAME | remove NAME
#
# Checks for the print system (CUPS) first and offers to install it. A printer is added driverless (IPP Everywhere)
# when it supports it, otherwise as a generic PostScript printer; --postscript skips driverless.
# NAME: letters, numbers, dash and underscore (e.g. ECC-Copier).
set -Eeuo pipefail

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="$(dirname "$TOOLS_DIR")/deploy/lib"
# shellcheck disable=SC1091
. "$LIB_DIR/ui.sh" 2>/dev/null || { echo "deploy/lib/ui.sh is missing next to this script." >&2; exit 1; }

die() { printf '\nERROR: %b\n' "$*" >&2; [ "$UI_WT" = 1 ] && ui_msg "Stopped" "$*"; exit 1; }
# A problem with one step: from the menu, say so and go back to the menu; from the command line, stop.
fail() { if [ "$CMD" = menu ]; then ui_msg "Problem" "$*"; else die "$*"; fi; }
say() { printf '%s\n' "$*" >&3; }
usage() { sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

CMD="${1:-menu}"
case "$CMD" in
    menu|add|list|test|remove) ;;
    -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $CMD (use add, list, test, remove or no option)" >&2; exit 2 ;;
esac
[ "$(id -u)" -eq 0 ] || { echo "Run as root: sudo $0 $*" >&2; exit 1; }

exec 3>&1
ui_init "Command Bridge - Printers"

valid_name() { [[ "$1" =~ ^[A-Za-z0-9_-]{1,40}$ ]]; }
valid_host() { [[ "$1" =~ ^[A-Za-z0-9.:-]{1,253}$ ]]; }
cups_installed() { command -v lpadmin >/dev/null 2>&1 && command -v lp >/dev/null 2>&1 && command -v lpstat >/dev/null 2>&1; }
port_open() { timeout 4 bash -c "exec 3<>/dev/tcp/$1/$2" 2>/dev/null; }   # port_open HOST PORT
# Printers added on this server, read from CUPS's own list. Asking lpstat about a name it doesn't know can make it
# search the network for a printer of that name, which takes several seconds.
# CUPS can take up to 30 seconds to rewrite that file, so printers added or removed in this run are tracked too.
PRINTERS_CONF=/etc/cups/printers.conf
SESSION_ADDED=" "; SESSION_REMOVED=" "
printer_names() {
    {
        [ -r "$PRINTERS_CONF" ] && sed -n 's/^<\(Default\)\{0,1\}Printer \(.*\)>$/\2/p' "$PRINTERS_CONF"
        printf '%s\n' $SESSION_ADDED
    } | awk -v gone="$SESSION_REMOVED" 'NF && index(gone, " " $0 " ") == 0 && !seen[$0]++'
}
queue_exists() { printer_names | grep -qxF -- "$1"; }

# Network printer discovery (cups-browsed) adds every printer it finds on the network, which slows things down and
# fills Settings > Printers. Command Bridge prints only to the printers added here, so it is switched off.
stop_discovery() {
    if systemctl is-enabled cups-browsed >/dev/null 2>&1 || systemctl is-active cups-browsed >/dev/null 2>&1; then
        systemctl disable --now cups-browsed >/dev/null 2>&1 || true
        say "Switched off automatic network printer discovery (cups-browsed); only printers added here are used."
    fi
}

# 1. The print system. Offer to install it when it is missing; nothing else works without it.
ensure_cups() {
    if cups_installed; then
        systemctl enable --now cups >/dev/null 2>&1 || true
        stop_discovery
        return 0
    fi
    ui_yesno "Print system" "The print system (CUPS) is not installed on this server.\n\nCommand Bridge needs it to send forms to a printer. Install it now? This downloads about 30 MB of packages." y \
        || { say "CUPS was not installed. Nothing changed."; exit 0; }
    ui_steps 1
    ui_stage "Installing the print system (CUPS)"
    export DEBIAN_FRONTEND=noninteractive
    { apt-get update -qq && apt-get install -y -qq --no-install-recommends cups cups-client cups-filters; } >/dev/null 2>&1 \
        || die "CUPS did not install. Check the server's internet access and apt sources, then run this again."
    systemctl enable --now cups >/dev/null 2>&1 || true
    stop_discovery
    cups_installed || die "CUPS installed but its commands were not found."
    ui_msg "Print system" "The print system (CUPS) is installed. Next, add a printer."
}

test_page() {  # test_page NAME  -> prints the CUPS test page
    local file=/usr/share/cups/data/testprint
    [ -f "$file" ] || file=/usr/share/cups/data/default-testpage.pdf
    [ -f "$file" ] || { echo "No CUPS test page file was found on this server. Print a form from Command Bridge instead."; return 1; }
    lp -d "$1" -t "Command Bridge test page" "$file" 2>&1
}

# Adds a printer. Sets ADDED_HOW. Returns non-zero with ADD_ERROR set when it can't.
add_printer() {  # add_printer NAME HOST auto|postscript
    local name=$1 host=$2 mode=$3 uri ppd
    ADDED_HOW=""; ADD_ERROR=""
    if [ "$mode" != postscript ]; then
        if port_open "$host" 631; then
            for uri in "ipp://$host/ipp/print" "ipp://$host:631/ipp/print" "ipp://$host/ipp"; do
                if lpadmin -p "$name" -E -v "$uri" -m everywhere >/dev/null 2>&1; then ADDED_HOW="driverless (IPP Everywhere)"; break; fi
            done
        fi
    fi
    if [ -z "$ADDED_HOW" ]; then
        if ! port_open "$host" 9100; then
            ADD_ERROR="Can't reach $host on port 631 (driverless) or 9100 (PostScript).\n\nCheck the IP address, that the printer is on, and that this server can reach it."
            return 1
        fi
        ppd="$(lpinfo -m 2>/dev/null | awk '/[Gg]eneric [Pp]ost[Ss]cript/ {print $1; exit}')"
        [ -n "$ppd" ] || ppd="drv:///sample.drv/generic.ppd"
        lpadmin -p "$name" -E -v "socket://$host:9100" -m "$ppd" >/dev/null 2>&1 \
            || { ADD_ERROR="The printer at $host could not be added."; return 1; }
        ADDED_HOW="generic PostScript"
    fi
    # Letter paper, and fit each page to it (landscape forms print the right way round).
    lpadmin -p "$name" -o media-default=Letter -o PageSize=Letter -o fit-to-page-default=true >/dev/null 2>&1 || true
    cupsenable "$name" >/dev/null 2>&1 || true
    cupsaccept "$name" >/dev/null 2>&1 || true
}

# ----- the steps, each usable from the menu or the command line -----

do_add() {  # do_add [NAME] [HOST] [auto|postscript]
    local name=${1:-} host=${2:-} mode=${3:-} out
    while [ -z "$name" ] || ! valid_name "$name" || queue_exists "$name"; do
        [ -n "$name" ] && ! valid_name "$name" && ui_msg "Printer name" "\"$name\" can't be used. Use letters, numbers, dash or underscore (up to 40), for example ECC-Copier."
        [ -n "$name" ] && valid_name "$name" && queue_exists "$name" && ui_msg "Printer name" "There is already a printer called $name. Pick another name, or remove that one first."
        [ "$CMD" = add ] && [ -n "${1:-}" ] && die "Printer name \"$name\" can't be used (invalid or already exists)."
        ui_ask name "Add a printer" "A short name for the printer on this server, for example ECC-Copier.\nPeople see a friendlier name, which you set later in Settings > Printers." "ECC-Printer"
    done
    while [ -z "$host" ] || ! valid_host "$host"; do
        [ -n "$host" ] && ui_msg "IP address" "\"$host\" doesn't look like an IP address or host name."
        [ "$CMD" = add ] && [ -n "${2:-}" ] && die "\"$host\" doesn't look like an IP address or host name."
        ui_ask host "Add a printer" "The printer's IP address, for example 192.0.2.25.\nIt's usually on the printer's network settings screen or a configuration page printed from its menu." ""
    done
    if [ -z "$mode" ]; then
        ui_menu mode "Add a printer" "How should this server talk to the printer?" auto \
            auto "Automatic: driverless if it can, otherwise PostScript (recommended)" \
            postscript "Generic PostScript (if driverless printed badly)"
    fi
    ui_steps 2
    ui_stage "Connecting to $host"
    if ! add_printer "$name" "$host" "$mode"; then
        fail "$ADD_ERROR"; return 0
    fi
    SESSION_ADDED="$SESSION_ADDED$name "; SESSION_REMOVED="${SESSION_REMOVED// $name / }"
    ui_stage "Added $name"
    say "Added $name at $host: $ADDED_HOW"
    if ui_yesno "Printer added" "$name was added ($ADDED_HOW).\n\nPrint a test page now?" y; then
        if out="$(test_page "$name")"; then
            ui_msg "Test page" "A test page was sent to $name.\n$out\n\nIf nothing prints, check the printer for paper or a jam. If the page looks wrong, remove the printer and add it again with Generic PostScript."
        else
            ui_msg "Test page" "The test page was not accepted:\n$out"
        fi
    fi
    ui_msg "Done" "$name is ready.\n\nIn Command Bridge, open Settings > Printers to give it a name people will recognize (for example \"ECC copier, 2nd floor\") and press Save."
}

do_list() {
    local f
    f="$(mktemp)"
    {
        echo "Printers on this server"
        echo "======================="
        if [ -z "$(printer_names)" ]; then
            echo "No printers yet."
        else
            # shellcheck disable=SC2046
            lpstat -v $(printer_names) 2>/dev/null || true
            echo
            # shellcheck disable=SC2046
            lpstat -p $(printer_names) 2>/dev/null || true
        fi
    } >"$f"
    if [ "$UI_WT" = 1 ]; then ui_textfile "Printers" "$f"; else cat "$f" >&3; fi
    rm -f "$f"
}

pick_printer() {  # pick_printer VAR "Title" "Prompt"
    local var=$1 names args=() n
    names="$(printer_names)"
    [ -n "$names" ] || { ui_msg "$2" "There are no printers yet. Add one first."; return 1; }
    while read -r n; do args+=("$n" "$(lpstat -v "$n" 2>/dev/null | sed 's/^device for [^:]*: //')"); done <<<"$names"
    ui_menu "$var" "$2" "$3" "${args[0]}" "${args[@]}"
}

do_test() {  # do_test [NAME]
    local name=${1:-} out
    if [ -z "$name" ]; then pick_printer name "Test page" "Which printer?" || return 0; fi
    queue_exists "$name" || { fail "No printer called $name."; return 0; }
    if out="$(test_page "$name")"; then ui_msg "Test page" "A test page was sent to $name.\n$out"; say "Test page sent to $name."
    else fail "The test page was not accepted: $out"; fi
}

do_remove() {  # do_remove [NAME] [--yes]
    local name=${1:-} force=${2:-}
    if [ -z "$name" ]; then pick_printer name "Remove a printer" "Which printer should be removed?" || return 0; fi
    queue_exists "$name" || { fail "No printer called $name."; return 0; }
    if [ "$force" != --yes ]; then
        ui_yesno "Remove a printer" "Remove $name? People will no longer be able to print to it from Command Bridge." n || return 0
    fi
    lpadmin -x "$name"
    SESSION_REMOVED="$SESSION_REMOVED$name "; SESSION_ADDED="${SESSION_ADDED// $name / }"
    ui_msg "Remove a printer" "$name was removed."
    say "Removed $name."
}

# ----- main -----
ensure_cups

case "$CMD" in
    add)
        mode=auto; [ "${4:-}" = --postscript ] && mode=postscript
        [ -n "${2:-}" ] && [ -n "${3:-}" ] || { echo "Usage: $0 add NAME IP [--postscript]" >&2; exit 2; }
        do_add "$2" "$3" "$mode" ;;
    list) do_list ;;
    test) [ -n "${2:-}" ] || { echo "Usage: $0 test NAME" >&2; exit 2; }; do_test "$2" ;;
    remove) [ -n "${2:-}" ] || { echo "Usage: $0 remove NAME" >&2; exit 2; }; do_remove "$2" --yes ;;
    menu)
        while true; do
            count="$(printer_names | grep -c . || true)"
            ui_menu choice "Printers" "Printers set up on this server: $count\n\nWhat would you like to do?" "$( [ "$count" = 0 ] && echo add || echo list )" \
                add "Add a printer" \
                list "List printers and their status" \
                test "Print a test page" \
                remove "Remove a printer" \
                quit "Quit"
            case "$choice" in
                add) do_add ;;
                list) do_list ;;
                test) do_test ;;
                remove) do_remove ;;
                *) break ;;
            esac
        done
        say "Done. Manage the printers people see in Command Bridge under Settings > Printers."
        ;;
esac
