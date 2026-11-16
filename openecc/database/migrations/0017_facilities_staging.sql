-- Incident facilities (#44) and the staging board (#45).
--  * Check-in locations become the incident facility list: external_checkin_locations gets facility details
--    (check-in point switch, map position, capacity, hours, contact, services, accessibility, notes). Existing
--    locations stay check-in points. Contact details and notes are never sent to the Check-In Gateway.
--  * Facility types: an admin-editable "Facility Types" list (Settings > Lookups) with defaults.
--  * Staging board: resource_staging (current staging status of each received resource) and
--    resource_staging_history (every change).
--  * Page permissions: FACILITIES (Facilities page) and STAGING (Staging board), hidden form codes like RESOURCES_UNIT.
--  * Dashboard: Open facilities for the Facilities Unit Leader, Staging for the Staging Area Manager (added only if
--    those positions have no dashboard setting yet).
-- Same as database/schema.sql and database/seed.sql. Safe to run more than once.

ALTER TABLE external_checkin_locations
  ADD COLUMN IF NOT EXISTS is_checkin_point tinyint(1) NOT NULL DEFAULT 1 AFTER lifecycle_status,
  ADD COLUMN IF NOT EXISTS latitude decimal(9,6) DEFAULT NULL AFTER is_checkin_point,
  ADD COLUMN IF NOT EXISTS longitude decimal(9,6) DEFAULT NULL AFTER latitude,
  ADD COLUMN IF NOT EXISTS capacity_people int(11) DEFAULT NULL AFTER longitude,
  ADD COLUMN IF NOT EXISTS capacity_vehicles int(11) DEFAULT NULL AFTER capacity_people,
  ADD COLUMN IF NOT EXISTS hours_text varchar(100) DEFAULT NULL AFTER capacity_vehicles,
  ADD COLUMN IF NOT EXISTS opened_at datetime DEFAULT NULL AFTER hours_text,
  ADD COLUMN IF NOT EXISTS closed_at datetime DEFAULT NULL AFTER opened_at,
  ADD COLUMN IF NOT EXISTS contact_name varchar(150) DEFAULT NULL AFTER closed_at,
  ADD COLUMN IF NOT EXISTS contact_phone varchar(50) DEFAULT NULL AFTER contact_name,
  ADD COLUMN IF NOT EXISTS services varchar(255) DEFAULT NULL AFTER contact_phone,
  ADD COLUMN IF NOT EXISTS accessibility varchar(255) DEFAULT NULL AFTER services,
  ADD COLUMN IF NOT EXISTS notes text DEFAULT NULL AFTER accessibility;

CREATE TABLE IF NOT EXISTS resource_staging (
  id int(11) NOT NULL AUTO_INCREMENT,
  incident_id int(11) NOT NULL,
  resource_unit_intake_id int(11) NOT NULL,
  location_id bigint(20) unsigned DEFAULT NULL,
  status enum('available','assigned','out_of_service','released') NOT NULL DEFAULT 'available',
  assignment varchar(255) DEFAULT NULL,
  note varchar(255) DEFAULT NULL,
  arrived_at datetime DEFAULT NULL,
  status_changed_at datetime DEFAULT NULL,
  updated_by_user_id int(11) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_resource_staging_intake (resource_unit_intake_id),
  KEY idx_resource_staging_incident (incident_id, location_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resource_staging_history (
  id int(11) NOT NULL AUTO_INCREMENT,
  incident_id int(11) NOT NULL,
  resource_unit_intake_id int(11) NOT NULL,
  location_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL,
  assignment varchar(255) DEFAULT NULL,
  note varchar(255) DEFAULT NULL,
  changed_by_user_id int(11) DEFAULT NULL,
  changed_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_resource_staging_history (incident_id, resource_unit_intake_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO lookup_list_items (list_category, item_value, item_description, is_active) VALUES
  ('Facility Types', 'Base', NULL, 1),
  ('Facility Types', 'Camp', NULL, 1),
  ('Facility Types', 'Cooling Centre', NULL, 1),
  ('Facility Types', 'ECC', NULL, 1),
  ('Facility Types', 'Helispot', NULL, 1),
  ('Facility Types', 'ICP', NULL, 1),
  ('Facility Types', 'Medical Aid Station', NULL, 1),
  ('Facility Types', 'Other', NULL, 1),
  ('Facility Types', 'Reception Centre', NULL, 1),
  ('Facility Types', 'Staging Area', NULL, 1),
  ('Facility Types', 'Warming Centre', NULL, 1);

INSERT INTO forms (form_code, form_title, is_active, default_non_editor_mode, notes) VALUES
  ('FACILITIES', 'Facilities', 1, 'hidden', 'Incident facility list (not an ICS form). Controls who can see and manage facilities.'),
  ('STAGING', 'Staging Board', 1, 'hidden', 'Staging status board (not an ICS form). Controls who can see and update staging.')
ON DUPLICATE KEY UPDATE form_title = VALUES(form_title);

INSERT INTO form_permissions
  (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, is_active)
VALUES
  ('FACILITIES', 'FAC', 1, 1, 1, 1, 0, 1),
  ('FACILITIES', 'LSC', 1, 1, 1, 1, 0, 1),
  ('FACILITIES', 'PSC', 1, 1, 1, 0, 0, 1),
  ('FACILITIES', 'IC', 1, 1, 1, 1, 1, 1),
  ('FACILITIES', 'UC', 1, 1, 1, 1, 0, 1),
  ('FACILITIES', 'DIC', 1, 1, 1, 0, 0, 1),
  ('FACILITIES', 'INCIDENT_POSITION', 1, 0, 0, 0, 0, 1),
  ('STAGING', 'SAM', 1, 1, 1, 1, 0, 1),
  ('STAGING', 'OSC', 1, 1, 1, 1, 0, 1),
  ('STAGING', 'RESL', 1, 1, 1, 0, 0, 1),
  ('STAGING', 'IC', 1, 1, 1, 1, 1, 1),
  ('STAGING', 'UC', 1, 1, 1, 1, 0, 1),
  ('STAGING', 'DIC', 1, 1, 1, 0, 0, 1),
  ('STAGING', 'PSC', 1, 0, 0, 0, 0, 1),
  ('STAGING', 'LSC', 1, 0, 0, 0, 0, 1),
  ('STAGING', 'DMOB', 1, 0, 0, 0, 0, 1),
  ('STAGING', 'DIVS', 1, 0, 0, 0, 0, 1),
  ('STAGING', 'BDIR', 1, 0, 0, 0, 0, 1)
ON DUPLICATE KEY UPDATE
  can_view = GREATEST(can_view, VALUES(can_view)),
  can_create = GREATEST(can_create, VALUES(can_create)),
  can_edit = GREATEST(can_edit, VALUES(can_edit)),
  can_submit_final = GREATEST(can_submit_final, VALUES(can_submit_final));

UPDATE app_settings
   SET setting_value = JSON_INSERT(setting_value, '$.FAC', JSON_ARRAY('facilities'), '$.SAM', JSON_ARRAY('staging'))
 WHERE setting_key = 'dashboard_panel_map' AND JSON_VALID(setting_value);
