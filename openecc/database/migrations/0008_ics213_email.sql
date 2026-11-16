-- ICS 213 by email (app/includes/ics213_messages.php):
--  * form_213_messages.ref_code: the reference code (CB-213-XXXXX) in the email subject and body, so a reply that
--    comes back by email can be matched to its message; reply_received_via / reply_email record a reply typed in
--    from an email.
--  * form_213_sends: one row per email sent (who to, sent or failed, by whom, when).
--  * form_205a_entries.phone / email / notes: the ICS 205A kept phone and email together in contact_methods; they
--    now have their own columns (the 213 "To" list uses the email), filled from existing entries below.
--  * app_settings.ics213_email_position_holders: 'ticked' (default) = email the people holding a "To" position only
--    when the sender ticks "Also email"; 'always' = always email them.
-- Same as database/schema.sql and seed.sql. Safe to run more than once.

ALTER TABLE form_213_messages
  ADD COLUMN IF NOT EXISTS ref_code varchar(30) DEFAULT NULL AFTER to_role_code,
  ADD COLUMN IF NOT EXISTS reply_received_via varchar(20) DEFAULT NULL AFTER reply_date_time,
  ADD COLUMN IF NOT EXISTS reply_email varchar(255) DEFAULT NULL AFTER reply_received_via;
CREATE UNIQUE INDEX IF NOT EXISTS uq_form_213_messages_ref ON form_213_messages (ref_code);

CREATE TABLE IF NOT EXISTS form_213_sends (
  id int(11) NOT NULL AUTO_INCREMENT,
  message_id int(11) NOT NULL,
  incident_id int(11) NOT NULL,
  recipient_type varchar(20) NOT NULL,
  recipient_label varchar(255) DEFAULT NULL,
  email varchar(255) NOT NULL,
  status varchar(20) NOT NULL,
  error varchar(255) DEFAULT NULL,
  sent_by_user_id int(11) DEFAULT NULL,
  sent_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_form_213_sends_message (message_id),
  KEY idx_form_213_sends_incident (incident_id),
  CONSTRAINT fk_form_213_sends_message FOREIGN KEY (message_id) REFERENCES form_213_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE form_205a_entries
  ADD COLUMN IF NOT EXISTS phone varchar(100) DEFAULT NULL AFTER contact_methods,
  ADD COLUMN IF NOT EXISTS email varchar(255) DEFAULT NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS notes text DEFAULT NULL AFTER email;

-- Existing entries were saved as "phone | email" (either part may be missing).
UPDATE form_205a_entries
   SET email = TRIM(SUBSTRING_INDEX(contact_methods, '|', -1))
 WHERE email IS NULL AND contact_methods LIKE '%@%'
   AND TRIM(SUBSTRING_INDEX(contact_methods, '|', -1)) LIKE '%@%';
UPDATE form_205a_entries
   SET phone = TRIM(SUBSTRING_INDEX(contact_methods, '|', 1))
 WHERE phone IS NULL AND contact_methods IS NOT NULL AND contact_methods <> ''
   AND TRIM(SUBSTRING_INDEX(contact_methods, '|', 1)) NOT LIKE '%@%';

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('ics213_email_position_holders', 'ticked')
ON DUPLICATE KEY UPDATE setting_value = IF(COALESCE(setting_value, '') = '', VALUES(setting_value), setting_value);
