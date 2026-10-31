-- CMS (D-018): renewal reminders and the audit log.
-- certificate_reminders: the primary key (certificate, stage) is what makes reminders idempotent. The row
-- and the queued emails are written in one transaction, so a crash never sends without recording or
-- records without sending.
-- audit_log: who did what to which record, and the NAMES of the fields that changed. Never the values,
-- so the log holds no personal data.

CREATE TABLE certificate_reminders (
  certificate_id UUID NOT NULL,
  stage TINYINT UNSIGNED NOT NULL,
  recipients TINYINT UNSIGNED NOT NULL DEFAULT 0,
  queued_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (certificate_id, stage),
  CONSTRAINT reminders_stage_chk CHECK (stage IN (60, 30, 7)),
  CONSTRAINT reminders_certificate_fk FOREIGN KEY (certificate_id) REFERENCES certificates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  actor_id UUID NULL,
  action VARCHAR(40) NOT NULL,
  entity_type VARCHAR(24) NOT NULL,
  entity_id VARCHAR(36) NULL,
  fields VARCHAR(500) NOT NULL DEFAULT '',
  KEY audit_at_idx (at),
  KEY audit_entity_idx (entity_type, entity_id),
  CONSTRAINT audit_actor_fk FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
