-- Contact details for lookup list values (Agencies and Jurisdictions, app/includes/lookups.php):
-- main phone, 24/7 line, email, website, notes, and when they were last verified (and by whom).
-- Organization contacts only: no named individuals' personal numbers (those go on the incident's ICS 205A).
-- Same columns as database/schema.sql. Safe to run more than once.
ALTER TABLE lookup_list_items
  ADD COLUMN IF NOT EXISTS phone varchar(50) DEFAULT NULL AFTER item_description,
  ADD COLUMN IF NOT EXISTS phone_24h varchar(50) DEFAULT NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS email varchar(190) DEFAULT NULL AFTER phone_24h,
  ADD COLUMN IF NOT EXISTS website varchar(255) DEFAULT NULL AFTER email,
  ADD COLUMN IF NOT EXISTS notes text DEFAULT NULL AFTER website,
  ADD COLUMN IF NOT EXISTS verified_at date DEFAULT NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS verified_by_user_id int(11) DEFAULT NULL AFTER verified_at;
