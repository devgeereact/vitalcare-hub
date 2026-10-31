-- CMS (D-018): certificates belong to a person (not a signed-in user) and come from an attendance or
-- are issued by hand with a reason. One certificate per attendance (unique attendee_id).
-- Revoked = status 'withdrawn' with revoked_at, revoked_by and revoke_reason set; the status values
-- themselves are unchanged, so the running code keeps working while this is applied.
-- learner_id becomes nullable: CMS certificates have a person instead. Additive otherwise.

ALTER TABLE certificates
  MODIFY learner_id UUID NULL,
  ADD COLUMN person_id UUID NULL AFTER learner_id,
  ADD COLUMN course_id UUID NULL AFTER person_id,
  ADD COLUMN attendee_id UUID NULL AFTER course_id,
  ADD COLUMN issued_on DATE NULL AFTER completed_on,
  ADD COLUMN issue_reason VARCHAR(500) NULL AFTER approved_by,
  ADD COLUMN revoked_at DATETIME(3) NULL AFTER issue_reason,
  ADD COLUMN revoked_by UUID NULL AFTER revoked_at,
  ADD COLUMN revoke_reason VARCHAR(500) NULL AFTER revoked_by,
  ADD UNIQUE KEY certificates_attendee_uq (attendee_id),
  ADD KEY certificates_person_idx (person_id, course_id),
  ADD KEY certificates_expiry_idx (status, expires_on),
  ADD CONSTRAINT certificates_person_fk FOREIGN KEY (person_id) REFERENCES people (id),
  ADD CONSTRAINT certificates_course_fk FOREIGN KEY (course_id) REFERENCES courses (id),
  ADD CONSTRAINT certificates_attendee_fk FOREIGN KEY (attendee_id) REFERENCES session_attendees (id),
  ADD CONSTRAINT certificates_revoker_fk FOREIGN KEY (revoked_by) REFERENCES users (id),
  ADD CONSTRAINT certificates_holder_chk CHECK (learner_id IS NOT NULL OR person_id IS NOT NULL),
  ADD CONSTRAINT certificates_revoked_chk CHECK (revoked_at IS NULL OR (status = 'withdrawn' AND revoked_by IS NOT NULL AND revoke_reason IS NOT NULL)),
  ADD CONSTRAINT certificates_issued_chk CHECK (issued_on IS NULL OR issued_on >= completed_on);
