-- Lookup lists (Settings > Lookups, app/includes/lookups.php).
--  * Default Incident Types, offered when setting up an incident. Same everywhere in Canada, so they are in the seed.
--  * Province-specific values (for example agencies) are NOT added here: a SysAdmin loads an optional starter list
--    from the Lookups page (app/includes/lookup_starters/).
--  * The Facilities, Resource Kinds, Resource Types and Positions lists are retired on the Lookups page (kept
--    elsewhere now); any values already in them are left as they are.
-- Same rows as database/seed.sql. Existing values (even disabled ones) are kept. Safe to run more than once.
INSERT IGNORE INTO lookup_list_items (list_category, item_value, item_description, is_active) VALUES
  ('Incident Types', 'Dangerous goods release', NULL, 1),
  ('Incident Types', 'Flood', NULL, 1),
  ('Incident Types', 'Infrastructure failure', NULL, 1),
  ('Incident Types', 'Other', NULL, 1),
  ('Incident Types', 'Pandemic / public health', NULL, 1),
  ('Incident Types', 'Planned event', NULL, 1),
  ('Incident Types', 'Search and rescue', NULL, 1),
  ('Incident Types', 'Severe weather', NULL, 1),
  ('Incident Types', 'Structure fire', NULL, 1),
  ('Incident Types', 'Train derailment', NULL, 1),
  ('Incident Types', 'Utility outage', NULL, 1),
  ('Incident Types', 'Water / wastewater emergency', NULL, 1),
  ('Incident Types', 'Wildfire', NULL, 1);
