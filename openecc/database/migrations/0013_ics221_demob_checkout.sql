-- ICS 221 Demobilization Check-Out from the Resources Unit page (app/forms/resources/ics221.php).
--  * Fix: recording a resource's receipt adds it to the demobilization tracker with status 'not_ready_for_demob',
--    but that value was missing from the column, so MariaDB refused the row. The value is added (and is the default).
--  * The Demobilization Unit Leader and the Planning Section Chief can open the Resources Unit page (read only) to
--    print ICS 221s.
-- Same as database/schema.sql and database/seed.sql. Safe to run more than once.

ALTER TABLE resource_demob_tracker
  MODIFY demob_status enum('not_ready_for_demob','planned','approved','released','demobilized') NOT NULL DEFAULT 'not_ready_for_demob';

INSERT INTO form_permissions
  (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, is_active)
VALUES
  ('RESOURCES_UNIT', 'DMOB', 1, 0, 0, 0, 0, 1),
  ('RESOURCES_UNIT', 'PSC', 1, 0, 0, 0, 0, 1)
ON DUPLICATE KEY UPDATE
  can_view = GREATEST(can_view, VALUES(can_view));
