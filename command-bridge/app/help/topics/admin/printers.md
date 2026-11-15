# Printers

People can send a form straight to a printer on your network with the **Print** button next to **Download PDF**.
Nothing has to be installed on anyone's computer, and it works from phones and tablets too. The Command Bridge
server prints directly to the printer's IP address.

## Adding a printer (on the server)

You need the printer's IP address. It's usually on the printer's network settings screen or on a configuration page
you can print from the printer's menu. On the Command Bridge server:

```
sudo /var/www/commandbridge/tools/setup_printer.sh
```

It works like the Check-In Gateway setup, with screens and a menu:

1. It checks for the print system (CUPS). If it isn't installed, it offers to install it.
2. A menu lets you **add**, **list**, **test** or **remove** printers.
3. **Add** asks for a short name (for example ECC-Copier), the IP address, and how to connect:
   - **Automatic** (recommended): **driverless** if the printer supports it, which most office printers and copiers
     from the last ten years do, including most Ricoh and HP models. Otherwise **generic PostScript**, which most
     business printers understand.
   - **Generic PostScript**: use this if driverless printed badly.

   It sets Letter paper with each page fitted to it (so landscape forms come out the right way round), then offers
   a test page.

For scripts, the same steps work without the menu:

| Command | What it does |
|---|---|
| `sudo ./setup_printer.sh add ECC-Copier 192.0.2.25` | Adds a printer (add `--postscript` to skip driverless). |
| `sudo ./setup_printer.sh list` | Lists the printers and their status. |
| `sudo ./setup_printer.sh test ECC-Copier` | Prints a test page. |
| `sudo ./setup_printer.sh remove ECC-Copier` | Removes the printer. |

## This page

- **Who can print:** anyone holding a position in the incident (the default), any signed-in user, or nobody.
  People can only print forms they can open, and SysAdmins can always print.
- **Name people see:** for example "ECC copier, 2nd floor". If it's empty, the server name is shown.
- **People can pick it:** untick to hide a printer from the Print page without removing it.
- **Test page** sends a one-page test from Command Bridge.

## Things to know

- **"Sent" means the printer accepted the job, not that paper came out.** If nothing prints, check the printer for a
  paper jam, then try **Test page** here.
- Each print is written to the audit log: who printed which form, on which printer, how many copies, and the job
  number.
- The printer must be reachable from the server. Printing goes straight to its IP address, not through a Windows
  print server.
- Forms with personal details, such as phone numbers on the ICS 205A, come out on a shared printer. Pick it up
  promptly.
- Printing is turned off in the demo.
