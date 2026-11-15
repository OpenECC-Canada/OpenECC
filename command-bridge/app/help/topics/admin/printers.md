# Printers

People can send a form straight to a printer on your network with the **Print** button next to **Download PDF**.
Nothing has to be installed on anyone's computer, and it works from phones and tablets too. The Command Bridge
server prints directly to the printer's IP address.

## Adding a printer (on the server)

You need the printer's IP address. It's usually on the printer's network settings screen or on a configuration page
you can print from the printer's menu. On the Command Bridge server:

```
cd /var/www/commandbridge/tools
sudo ./setup_printer.sh add ECC-Copier 192.0.2.25
```

The script:

- installs the print system (CUPS) the first time
- connects **driverless** if the printer supports it. Most office printers and copiers from the last ten years do,
  including most Ricoh and HP models.
- otherwise uses **generic PostScript**, which most business printers understand
- sets Letter paper, fits each page to it (so landscape forms come out the right way round), and prints a test page

Other commands:

| Command | What it does |
|---|---|
| `sudo ./setup_printer.sh list` | Lists the printers and their status. |
| `sudo ./setup_printer.sh test ECC-Copier` | Prints a test page. |
| `sudo ./setup_printer.sh remove ECC-Copier` | Removes the printer. |
| `sudo ./setup_printer.sh add ECC-Copier 192.0.2.25 --postscript` | Skips driverless, if it printed badly. |

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
