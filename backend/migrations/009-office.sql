-- CMS (D-018): note history and follow-ups on a person, organisation or enquiry, and the enquiry inbox.
-- Notes are append-only through the API. Enquiry statuses widen to new, in_progress and closed; the
-- older values stay allowed so no existing row has to be rewritten (D-018 item 10).

CREATE TABLE notes (
  id UUID NOT NULL PRIMARY KEY,
  subject_type VARCHAR(12) NOT NULL,
  subject_id UUID NOT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'note',
  body TEXT NOT NULL,
  author_id UUID NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY notes_subject_idx (subject_type, subject_id, created_at),
  CONSTRAINT notes_subject_chk CHECK (subject_type IN ('person', 'organisation', 'enquiry')),
  CONSTRAINT notes_kind_chk CHECK (kind IN ('note', 'reply')),
  CONSTRAINT notes_author_fk FOREIGN KEY (author_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE follow_ups (
  id UUID NOT NULL PRIMARY KEY,
  subject_type VARCHAR(12) NOT NULL,
  subject_id UUID NOT NULL,
  title VARCHAR(200) NOT NULL,
  due_on DATE NOT NULL,
  assignee_id UUID NULL,
  done_at DATETIME(3) NULL,
  done_by UUID NULL,
  created_by UUID NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY follow_ups_open_idx (done_at, due_on),
  KEY follow_ups_subject_idx (subject_type, subject_id),
  CONSTRAINT follow_ups_subject_chk CHECK (subject_type IN ('person', 'organisation', 'enquiry')),
  CONSTRAINT follow_ups_done_chk CHECK (done_at IS NULL OR done_by IS NOT NULL),
  CONSTRAINT follow_ups_assignee_fk FOREIGN KEY (assignee_id) REFERENCES users (id),
  CONSTRAINT follow_ups_done_by_fk FOREIGN KEY (done_by) REFERENCES users (id),
  CONSTRAINT follow_ups_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE enquiries DROP CONSTRAINT enquiries_status_chk;

ALTER TABLE enquiries
  ADD COLUMN assignee_id UUID NULL AFTER status,
  ADD COLUMN closed_at DATETIME(3) NULL AFTER assignee_id,
  ADD CONSTRAINT enquiries_status_cms_chk CHECK (status IN ('new', 'in_progress', 'closed', 'read', 'answered', 'spam')),
  ADD CONSTRAINT enquiries_assignee_fk FOREIGN KEY (assignee_id) REFERENCES users (id);
