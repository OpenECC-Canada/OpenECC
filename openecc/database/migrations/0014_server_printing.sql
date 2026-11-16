-- Printing forms straight to a network printer from the server (app/includes/printing.php, Settings > Printers).
-- Printers themselves are added on the server with tools/setup_printer.sh; these are the app's settings.
--  * print_access: who may print - 'positions' (anyone holding a position in the incident), 'signed_in' or 'off'
--  * printer_labels: friendly names and which printers people can pick (JSON, set on the Printers page)
-- Same rows as database/seed.sql. Existing values are kept. Safe to run more than once.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
  ('print_access', 'positions'),
  ('printer_labels', '{}');
