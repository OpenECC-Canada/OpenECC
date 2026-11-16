-- Check-In Recorder and Staging Area Manager: one code each.
-- The app and the Check-In Gateway use the role codes CIR and SAM, but the position list only had them as inactive
-- CHECK_IN_RECORDER / STAGING_AREA_MANAGER rows. So CIR and SAM had no section and no supervisor (for example, their
-- ICS 214 went to the IC). This puts CIR and SAM in the position list:
--   CIR - Planning, reports to the Resources Unit Leader (RESL)
--   SAM - Operations, reports to the Operations Section Chief (OSC)
-- and moves any assignments or approvals on the old long-code roles to CIR / SAM. The old roles stay inactive.
-- Same as database/seed.sql. Safe to run more than once.

INSERT INTO incident_position_catalog
  (group_name, group_sort, sort_order, acronym, full_title, reports_to_acronym, is_section_chief_or_command, is_active) VALUES
  ('Planning', 30, 35, 'CIR', 'Check-In Recorder', 'RESL', 0, 1),
  ('Operations', 20, 50, 'SAM', 'Staging Area Manager', 'OSC', 0, 1)
ON DUPLICATE KEY UPDATE group_name = VALUES(group_name), group_sort = VALUES(group_sort), sort_order = VALUES(sort_order),
  full_title = VALUES(full_title), reports_to_acronym = VALUES(reports_to_acronym), is_active = 1;

UPDATE incident_position_catalog SET reports_to_acronym = 'CIR' WHERE reports_to_acronym = 'CHECK_IN_RECORDER';
UPDATE incident_position_catalog SET reports_to_acronym = 'SAM' WHERE reports_to_acronym = 'STAGING_AREA_MANAGER';
DELETE FROM incident_position_catalog WHERE acronym IN ('CHECK_IN_RECORDER', 'STAGING_AREA_MANAGER');

UPDATE incident_positions ip
  JOIN roles old_r ON old_r.id = ip.role_id AND old_r.role_code = 'CHECK_IN_RECORDER'
  JOIN roles new_r ON new_r.role_code = 'CIR'
SET ip.role_id = new_r.id;
UPDATE incident_positions ip
  JOIN roles old_r ON old_r.id = ip.role_id AND old_r.role_code = 'STAGING_AREA_MANAGER'
  JOIN roles new_r ON new_r.role_code = 'SAM'
SET ip.role_id = new_r.id;
UPDATE approvals a
  JOIN roles old_r ON old_r.id = a.approver_role_id AND old_r.role_code = 'CHECK_IN_RECORDER'
  JOIN roles new_r ON new_r.role_code = 'CIR'
SET a.approver_role_id = new_r.id;
UPDATE approvals a
  JOIN roles old_r ON old_r.id = a.approver_role_id AND old_r.role_code = 'STAGING_AREA_MANAGER'
  JOIN roles new_r ON new_r.role_code = 'SAM'
SET a.approver_role_id = new_r.id;

UPDATE roles SET is_active = 0 WHERE role_code IN ('CHECK_IN_RECORDER', 'STAGING_AREA_MANAGER');
