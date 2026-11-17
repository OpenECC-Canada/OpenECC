# OpenECC appliance: a box that installs itself

> **Experimental** (issue #57). Tested so far only in pieces. Expect a few rounds of fixes.

The appliance is a small 64-bit PC (for example a LattePanda 3 Delta or a mini PC) that you plug in, join over Wi-Fi,
and set up from a web page. No terminal and no internet are needed once it's built.

You need:
- a 64-bit Intel or AMD PC with an **NVMe SSD** and **Wi-Fi that can run a hotspot** (see "Check the Wi-Fi" below)
- a **wired internet connection** for the PC while Debian installs (about 20 minutes)
- a USB stick (2 GB or more; it gets erased)
- a Linux computer to build the installer on (these steps use Arch; any Linux with `xorriso` works)

## 1. Build the installer (on your Linux computer)

```bash
sudo pacman -S --needed xorriso openssl git          # Arch; on Debian/Ubuntu: sudo apt install xorriso openssl git
git clone https://github.com/OpenECC-Canada/OpenECC.git
cd OpenECC
./openecc/appliance/build_iso.sh ~/Downloads/debian-13.7.0-amd64-netinst.iso
```

Get the Debian ISO from <https://www.debian.org/download> (the "64-bit PC netinst iso"), and check it:
```bash
sha512sum -c SHA512SUMS --ignore-missing
```

The builder asks:

| Question | Notes |
|---|---|
| Include a desktop? | **y** = when a screen is plugged in, it logs in by itself and opens the setup page |
| Time zone | for example `America/Edmonton` |
| Wi-Fi network name | the setup hotspot, for example `OpenECC-Setup` |
| Wi-Fi password | a random one is suggested; the setup page makes you change it |
| Setup code | a random 8-digit code is suggested; the setup page asks for it |
| Password for the `openecc` login | the PC's own account (desktop, SSH, `sudo`) |

It ends with a box of values to **write on a label** for the PC, and the file
`openecc-appliance-<version>.iso` in the current folder.

## 2. Write it to a USB stick

Find the stick's name, then write the ISO. **This erases the stick. Pick the right one.**
```bash
lsblk                     # the stick is the one about the size of your USB stick, for example sdb
sudo dd if=openecc-appliance-0.9.0.iso of=/dev/sdX bs=4M status=progress oflag=sync
```
Replace `sdX` with the stick (for example `sdb`, not `sdb1`).

## 3. Install on the PC

1. Plug in the network cable, the USB stick, and (optionally) a screen and keyboard.
2. Boot from the USB stick. On a LattePanda press **F7** at power-on for the boot menu. Other PCs often use F12, F11 or Esc.
3. The menu starts **Install OpenECC appliance** by itself after 10 seconds.
4. The installer runs on its own. It stops **once**: it shows the disk it will erase (the NVMe SSD) and asks
   **"Write the changes to disks?"**. Check that it's the SSD and choose **Yes**.
5. About 20 minutes later it restarts. **Take out the USB stick** when the screen goes dark, or it starts the
   installer again.

## 4. Set up OpenECC

**With a screen:** the desktop logs in by itself and opens the setup page.

**Without one:** on a laptop, tablet or phone, join the Wi-Fi from the label and open **https://10.42.0.1**.
The browser warns about the certificate. That's expected for a box with no internet, so continue anyway.
Then enter the **setup code** from the label.

The setup page asks:
- the time zone, and whether to set the box's clock from your device
- the first administrator (username, name, password)
- a **new** name and password for the box's Wi-Fi
- the web address (keep `10.42.0.1` for use over the box's own Wi-Fi)

Press **Install OpenECC** and watch the progress. When it says it's installed, the setup page closes and the Wi-Fi
switches to the new name and password about 20 seconds later. Join it again and open **https://10.42.0.1**.

## Check the Wi-Fi

Not every Wi-Fi card can run a hotspot. On the PC (from the desktop's terminal, or over SSH), run:
```bash
iw list | grep -A8 "Supported interface modes"
```
The output must include `* AP`. Intel cards usually run a hotspot on 2.4 GHz only. A built-in card serves a small
ECC; for more than about 10 to 15 devices, plug the box into a proper Wi-Fi router.

## If something goes wrong

- **No `OpenECC-Setup` Wi-Fi:** the card may not support hotspots. Plug the box into your network with a cable and
  open `https://<its address>`. The console login screen shows the address.
- **The setup page says the install stopped:** read the last lines it shows, fix the answer and press Install again.
  The full log is `/var/lib/openecc-setup/progress.log`.
- **Logs on the box:**
  - `/var/log/openecc-setup-mode.log`: setting up setup mode, at the end of the Debian install
  - `/var/lib/openecc-setup/progress.log`: the OpenECC install
  - `journalctl -u openecc-setup`: the root helper

## How it works (for maintainers)

| File | What it does |
|---|---|
| `appliance/build_iso.sh` | Remasters the Debian netinst ISO. It adds `preseed.cfg`, a boot entry and the OpenECC files (`/openecc` on the ISO), and repacks with `xorriso … -boot_image any replay` |
| `appliance/preseed.cfg` | Answers for the Debian installer. Picks the first NVMe disk but still asks before erasing it. Runs `setup_mode.sh` at the end |
| `appliance/setup_mode.sh` | Installs every package OpenECC needs, the NetworkManager hotspot (`OpenECC-Hotspot`, 10.42.0.1, shared mode), the setup site, the root helper and desktop auto-login |
| `appliance/setup-web/` | The setup page. It runs as `www-data` and only writes `/var/lib/openecc-setup/request.json` |
| `appliance/run_setup.sh` | Installed as `/usr/local/sbin/openecc-setup-run`, started by `openecc-setup.path`. It checks the answers again, runs `install.sh` unattended (`CB_ASSUME_YES=1`, `CB_SKIP_PACKAGES=1`, self-signed HTTPS), then `--finish` removes setup mode and changes the Wi-Fi |

`setup_mode.sh` also works on a Debian 13 PC you installed yourself:
```bash
sudo WIFI_SSID=OpenECC-Setup WIFI_PSK=changeme123 SETUP_CODE=12345678 bash openecc/appliance/setup_mode.sh
```
