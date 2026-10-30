-- Retry back-off for the outbound email queue. A failed send is retried later, not on the next minute,
-- so a short mail-server outage does not burn all attempts. `next_attempt_at` is when a queued row may
-- next be tried; the sender never touches a row before then.

ALTER TABLE outbox_email
  ADD COLUMN next_attempt_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER attempts,
  ADD KEY outbox_due_idx (status, next_attempt_at);
