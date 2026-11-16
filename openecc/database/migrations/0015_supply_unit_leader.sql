-- Supply Unit Leader (SPUL) was used by the app (Logistics queue, ICS 213RR "order placed by", ICS 203/207) but was
-- missing from the position list, so nobody could be assigned to it. Adds the role, the position (Logistics, reports
-- to the LSC) and edit access to the ICS 213RR, like the rest of Logistics.
-- Same rows as database/seed.sql. Existing rows are kept (names refreshed only). Safe to run more than once.

INSERT INTO roles (role_code, role_name, is_command_role, is_active) VALUES
  ('SPUL', 'Supply Unit Leader', 0, 1)
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO incident_position_catalog
  (group_name, group_sort, sort_order, acronym, full_title, reports_to_acronym, is_section_chief_or_command, is_active) VALUES
  ('Logistics', 40, 45, 'SPUL', 'Supply Unit Leader', 'LSC', 0, 1)
ON DUPLICATE KEY UPDATE full_title = VALUES(full_title);

INSERT INTO form_permissions
  (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, is_active)
VALUES
  ('ICS213RR', 'SPUL', 1, 1, 1, 0, 0, 1)
ON DUPLICATE KEY UPDATE
  can_view = GREATEST(can_view, VALUES(can_view)),
  can_create = GREATEST(can_create, VALUES(can_create)),
  can_edit = GREATEST(can_edit, VALUES(can_edit));
