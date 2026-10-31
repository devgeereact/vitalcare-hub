-- CMS (D-018): courses carry the public facts the owner enters; the public pages read published rows
-- only. A training session is one delivery of a course; attendees record what happened.
-- Session times are stored in UTC; the API converts to and from Europe/London.

CREATE TABLE courses (
  id UUID NOT NULL PRIMARY KEY,
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(190) NOT NULL,
  summary TEXT NOT NULL,
  duration_text VARCHAR(80) NOT NULL DEFAULT '',
  hours DECIMAL(5,2) NULL,
  format VARCHAR(12) NOT NULL DEFAULT 'in_person',
  price_pence INT UNSIGNED NULL,
  price_note VARCHAR(160) NOT NULL DEFAULT '',
  assessment_method VARCHAR(255) NOT NULL DEFAULT '',
  renewal_months SMALLINT UNSIGNED NULL,
  accreditation_note VARCHAR(500) NOT NULL DEFAULT '',
  published TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME(3) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY courses_slug_uq (slug),
  KEY courses_published_idx (published, sort_order),
  CONSTRAINT courses_slug_chk CHECK (slug REGEXP '(?-i)^[a-z0-9]+(-[a-z0-9]+)*$'),
  CONSTRAINT courses_title_chk CHECK (title <> ''),
  CONSTRAINT courses_format_chk CHECK (format IN ('in_person', 'online', 'blended')),
  CONSTRAINT courses_hours_chk CHECK (hours IS NULL OR hours > 0),
  CONSTRAINT courses_renewal_chk CHECK (renewal_months IS NULL OR renewal_months BETWEEN 1 AND 120),
  CONSTRAINT courses_published_chk CHECK (published = 0 OR published_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE training_sessions (
  id UUID NOT NULL PRIMARY KEY,
  course_id UUID NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  venue VARCHAR(255) NULL,
  online_link VARCHAR(500) NULL,
  trainer_name VARCHAR(190) NOT NULL DEFAULT '',
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  status VARCHAR(12) NOT NULL DEFAULT 'scheduled',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  KEY sessions_starts_idx (starts_at),
  KEY sessions_course_idx (course_id),
  CONSTRAINT sessions_status_chk CHECK (status IN ('scheduled', 'cancelled', 'completed')),
  CONSTRAINT sessions_times_chk CHECK (ends_at > starts_at),
  CONSTRAINT sessions_capacity_chk CHECK (capacity BETWEEN 1 AND 500),
  CONSTRAINT sessions_course_fk FOREIGN KEY (course_id) REFERENCES courses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_attendees (
  id UUID NOT NULL PRIMARY KEY,
  session_id UUID NOT NULL,
  person_id UUID NOT NULL,
  attendance VARCHAR(12) NOT NULL DEFAULT 'booked',
  result VARCHAR(14) NULL,
  marked_at DATETIME(3) NULL,
  marked_by UUID NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY attendees_session_person_uq (session_id, person_id),
  KEY attendees_person_idx (person_id),
  CONSTRAINT attendees_attendance_chk CHECK (attendance IN ('booked', 'attended', 'no_show', 'cancelled')),
  CONSTRAINT attendees_result_chk CHECK (result IS NULL OR result IN ('pass', 'fail', 'not_assessed')),
  CONSTRAINT attendees_session_fk FOREIGN KEY (session_id) REFERENCES training_sessions (id),
  CONSTRAINT attendees_person_fk FOREIGN KEY (person_id) REFERENCES people (id),
  CONSTRAINT attendees_marked_by_fk FOREIGN KEY (marked_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
