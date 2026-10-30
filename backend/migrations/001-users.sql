-- Identity and tenancy. Clerk holds the sign-in; this table holds who may use the system, and as what.
-- No data is created here: users are added by an administrator (or by bin/seed-super-admin.php).

CREATE TABLE organisations (
  id UUID NOT NULL PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT organisations_status_chk CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id UUID NOT NULL PRIMARY KEY,
  clerk_user_id VARCHAR(64) NULL,
  email VARCHAR(254) NOT NULL,
  email_lower VARCHAR(254) AS (LOWER(email)) PERSISTENT,
  display_name VARCHAR(190) NOT NULL DEFAULT '',
  role VARCHAR(16) NOT NULL,
  organisation_id UUID NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  last_sign_in_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY users_clerk_user_id_uq (clerk_user_id),
  UNIQUE KEY users_email_lower_uq (email_lower),
  KEY users_organisation_idx (organisation_id),
  CONSTRAINT users_role_chk CHECK (role IN ('super_admin', 'admin', 'manager', 'trainer', 'learner')),
  CONSTRAINT users_status_chk CHECK (status IN ('active', 'disabled')),
  CONSTRAINT users_organisation_fk FOREIGN KEY (organisation_id) REFERENCES organisations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
