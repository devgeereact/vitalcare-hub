-- CMS (D-018): people are learners. They never sign in, so they are not users. An organisation is a
-- care home or employer; its contact may be copied on renewal reminders when reminder_copy is set.
-- Additive only: new columns have defaults, nothing is renamed or dropped.

ALTER TABLE organisations
  ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'employer' AFTER name,
  ADD COLUMN contact_name VARCHAR(190) NULL AFTER status,
  ADD COLUMN contact_email VARCHAR(254) NULL AFTER contact_name,
  ADD COLUMN phone VARCHAR(30) NULL AFTER contact_email,
  ADD COLUMN address VARCHAR(500) NULL AFTER phone,
  ADD COLUMN reminder_copy TINYINT(1) NOT NULL DEFAULT 0 AFTER address,
  ADD KEY organisations_name_idx (name),
  ADD CONSTRAINT organisations_kind_chk CHECK (kind IN ('care_home', 'employer', 'other'));

CREATE TABLE people (
  id UUID NOT NULL PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NULL,
  email_lower VARCHAR(254) AS (LOWER(email)) PERSISTENT,
  phone VARCHAR(30) NULL,
  job_title VARCHAR(120) NULL,
  organisation_id UUID NULL,
  reminders_opt_out TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  created_by UUID NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY people_email_idx (email_lower),
  KEY people_name_idx (last_name, first_name),
  KEY people_organisation_idx (organisation_id),
  CONSTRAINT people_status_chk CHECK (status IN ('active', 'archived')),
  CONSTRAINT people_name_chk CHECK (first_name <> '' AND last_name <> ''),
  CONSTRAINT people_organisation_fk FOREIGN KEY (organisation_id) REFERENCES organisations (id),
  CONSTRAINT people_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
