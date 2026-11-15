-- ICS 213: approval before a message is emailed (app/includes/ics213_messages.php).
--  * A message with email recipients waits for the sender's supervisor (or the IC) to approve it; dashboard-only
--    messages and the IC's own messages go straight out.
--  * form_213_messages.status: awaiting_approval, returned, sent, cancelled ("replied" is shown when reply is filled).
--    Messages written before this change are already sent.
--  * form_213_sends rows can be 'pending' (waiting for approval, with approver_user_id) or 'cancelled'.
-- Same as database/schema.sql. Safe to run more than once.

ALTER TABLE form_213_messages
  ADD COLUMN IF NOT EXISTS status varchar(20) NOT NULL DEFAULT 'sent' AFTER reply_email,
  ADD COLUMN IF NOT EXISTS signed_at datetime DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS approver_user_id int(11) DEFAULT NULL AFTER signed_at,
  ADD COLUMN IF NOT EXISTS approval_requested_at datetime DEFAULT NULL AFTER approver_user_id,
  ADD COLUMN IF NOT EXISTS approved_by_user_id int(11) DEFAULT NULL AFTER approval_requested_at,
  ADD COLUMN IF NOT EXISTS approved_at datetime DEFAULT NULL AFTER approved_by_user_id,
  ADD COLUMN IF NOT EXISTS return_reason varchar(300) DEFAULT NULL AFTER approved_at,
  ADD COLUMN IF NOT EXISTS sent_at datetime DEFAULT NULL AFTER return_reason;

UPDATE form_213_messages SET signed_at = created_at WHERE signed_at IS NULL AND status = 'sent';
UPDATE form_213_messages SET sent_at = created_at WHERE sent_at IS NULL AND status = 'sent';

ALTER TABLE form_213_sends
  ADD COLUMN IF NOT EXISTS approver_user_id int(11) DEFAULT NULL AFTER sent_by_user_id;
