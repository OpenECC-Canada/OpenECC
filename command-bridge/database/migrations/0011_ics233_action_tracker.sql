-- ICS 233 Incident Open Action Tracker (app/forms/ics233, app/includes/ics233_actions.php).
-- One running list of actions per incident. Kept by the Planning Section Chief (with the IC, Unified Command, Deputy IC
-- and Documentation Unit Leader); everyone in the incident can read it, and the POC position updates its own actions.
-- Same as database/schema.sql and database/seed.sql. Safe to run more than once.

CREATE TABLE IF NOT EXISTS form_233_actions (
  id int(11) NOT NULL AUTO_INCREMENT,
  incident_id int(11) NOT NULL,
  item_no int(11) NOT NULL,
  item varchar(500) NOT NULL,
  poc_role_code varchar(50) DEFAULT NULL,
  poc_text varchar(200) DEFAULT NULL,
  briefed_at datetime DEFAULT NULL,
  briefed_by_user_id int(11) DEFAULT NULL,
  start_date date NOT NULL,
  status_text varchar(255) NOT NULL DEFAULT '',
  target_date date DEFAULT NULL,
  actual_date date DEFAULT NULL,
  is_completed tinyint(1) NOT NULL DEFAULT 0,
  created_by_user_id int(11) DEFAULT NULL,
  deleted_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_form_233_incident (incident_id, is_completed, item_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO forms (form_code, form_title, is_active, default_non_editor_mode, notes) VALUES
  ('ICS233', 'ICS 233 - Incident Open Action Tracker', 1, 'read_only', 'Open action tracker (one list per incident).')
ON DUPLICATE KEY UPDATE form_title = VALUES(form_title);

INSERT INTO form_definitions (form_type, form_number, form_name, typical_preparer_role_code, requires_operational_period) VALUES
  ('ICS233', '233', 'Incident Open Action Tracker', 'PSC', 0)
ON DUPLICATE KEY UPDATE form_name = VALUES(form_name);

INSERT INTO form_permissions
  (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, is_active)
VALUES
  ('ICS233', 'PSC', 1, 1, 1, 1, 0, 1),
  ('ICS233', 'IC', 1, 1, 1, 1, 1, 1),
  ('ICS233', 'UC', 1, 1, 1, 1, 0, 1),
  ('ICS233', 'DIC', 1, 1, 1, 0, 0, 1),
  ('ICS233', 'DOCL', 1, 1, 1, 0, 0, 1),
  ('ICS233', 'INCIDENT_POSITION', 1, 0, 0, 0, 0, 1)
ON DUPLICATE KEY UPDATE
  can_view = GREATEST(can_view, VALUES(can_view)),
  can_create = GREATEST(can_create, VALUES(can_create)),
  can_edit = GREATEST(can_edit, VALUES(can_edit)),
  can_submit_final = GREATEST(can_submit_final, VALUES(can_submit_final));
