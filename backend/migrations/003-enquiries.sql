-- Contact-form enquiries and the outbound email queue. Handlers only queue mail; a cron job sends it.
-- The sender's IP is stored as a salted-free SHA-256 hash only, for abuse review, never raw.

CREATE TABLE enquiries (
  id UUID NOT NULL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(254) NOT NULL,
  phone VARCHAR(30) NULL,
  message TEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'new',
  ip_hash CHAR(64) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY enquiries_status_created_idx (status, created_at),
  CONSTRAINT enquiries_status_chk CHECK (status IN ('new', 'read', 'answered', 'spam'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE outbox_email (
  id UUID NOT NULL PRIMARY KEY,
  to_email VARCHAR(254) NOT NULL,
  reply_to VARCHAR(254) NULL,
  subject VARCHAR(200) NOT NULL,
  body_text TEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  sent_at DATETIME(3) NULL,
  KEY outbox_status_created_idx (status, created_at),
  CONSTRAINT outbox_status_chk CHECK (status IN ('queued', 'sent', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
