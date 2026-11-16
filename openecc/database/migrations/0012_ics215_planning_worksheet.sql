-- ICS 215 Operational Planning Worksheet (app/forms/ics215).
-- One worksheet per operational period: up to 12 resource columns and any number of work assignments, each with
-- resources required and on hand. Prepared by the Operations Section Chief, approved by the Planning Section Chief.
-- Same as database/schema.sql and database/seed.sql. Safe to run more than once.

CREATE TABLE IF NOT EXISTS form_215_headers (
  id int(11) NOT NULL AUTO_INCREMENT,
  form_record_id int(11) NOT NULL,
  resource_columns text DEFAULT NULL,
  prepared_by_name varchar(150) DEFAULT NULL,
  prepared_by_position varchar(150) DEFAULT NULL,
  prepared_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_form_215_headers_record (form_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_215_rows (
  id int(11) NOT NULL AUTO_INCREMENT,
  form_record_id int(11) NOT NULL,
  row_order int(11) NOT NULL DEFAULT 0,
  branch varchar(100) DEFAULT NULL,
  division_group varchar(100) DEFAULT NULL,
  work_assignment text DEFAULT NULL,
  resources_json text DEFAULT NULL,
  overhead text DEFAULT NULL,
  special_equipment text DEFAULT NULL,
  reporting_location varchar(255) DEFAULT NULL,
  arrival_time varchar(50) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_form_215_rows_record (form_record_id, row_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO forms (form_code, form_title, is_active, default_non_editor_mode, notes) VALUES
  ('ICS215', 'ICS 215 - Operational Planning Worksheet', 1, 'read_only', 'Operational planning worksheet.')
ON DUPLICATE KEY UPDATE form_title = VALUES(form_title);

INSERT INTO form_definitions (form_type, form_number, form_name, typical_preparer_role_code, requires_operational_period) VALUES
  ('ICS215', '215', 'Operational Planning Worksheet', 'OSC', 1)
ON DUPLICATE KEY UPDATE form_name = VALUES(form_name);

INSERT INTO form_permissions
  (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, is_active)
VALUES
  ('ICS215', 'OSC', 1, 1, 1, 1, 0, 1),
  ('ICS215', 'PSC', 1, 1, 1, 0, 0, 1),
  ('ICS215', 'IC', 1, 1, 1, 1, 1, 1),
  ('ICS215', 'UC', 1, 1, 1, 1, 0, 1),
  ('ICS215', 'DIC', 1, 1, 1, 0, 0, 1),
  ('ICS215', 'LSC', 1, 0, 0, 0, 0, 1),
  ('ICS215', 'FSC', 1, 0, 0, 0, 0, 1),
  ('ICS215', 'SOFR', 1, 0, 0, 0, 0, 1),
  ('ICS215', 'RESL', 1, 0, 0, 0, 0, 1),
  ('ICS215', 'SITL', 1, 0, 0, 0, 0, 1),
  ('ICS215', 'DOCL', 1, 0, 0, 0, 0, 1)
ON DUPLICATE KEY UPDATE
  can_view = GREATEST(can_view, VALUES(can_view)),
  can_create = GREATEST(can_create, VALUES(can_create)),
  can_edit = GREATEST(can_edit, VALUES(can_edit)),
  can_submit_final = GREATEST(can_submit_final, VALUES(can_submit_final));
