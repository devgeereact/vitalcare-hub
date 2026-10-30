-- Certificates. The row is an immutable record: name, course title and hours are snapshots taken at
-- issue, so editing a course or a profile later never rewrites an issued certificate.
-- Nothing is public until status = 'approved'. Public verification uses `code`, which is random
-- (12 characters from a 31-character alphabet, about 59 bits) and never derived from anything else.

CREATE TABLE certificates (
  id UUID NOT NULL PRIMARY KEY,
  code CHAR(12) NOT NULL,
  learner_id UUID NOT NULL,
  learner_name VARCHAR(190) NOT NULL,
  course_title VARCHAR(190) NOT NULL,
  course_hours DECIMAL(5,2) NULL,
  completed_on DATE NOT NULL,
  expires_on DATE NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  approved_at DATETIME(3) NULL,
  approved_by UUID NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY certificates_code_uq (code),
  KEY certificates_learner_idx (learner_id),
  CONSTRAINT certificates_status_chk CHECK (status IN ('draft', 'approved', 'withdrawn')),
  CONSTRAINT certificates_approved_chk CHECK (status <> 'approved' OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)),
  CONSTRAINT certificates_dates_chk CHECK (expires_on IS NULL OR expires_on >= completed_on),
  CONSTRAINT certificates_hours_chk CHECK (course_hours IS NULL OR course_hours > 0),
  CONSTRAINT certificates_learner_fk FOREIGN KEY (learner_id) REFERENCES users (id),
  CONSTRAINT certificates_approver_fk FOREIGN KEY (approved_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
