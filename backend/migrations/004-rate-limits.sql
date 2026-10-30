-- Fixed-window counters for public endpoints. `bucket` is a name plus a SHA-256 of the caller, so no
-- raw IP address is stored. Old windows are deleted by the maintenance cron.

CREATE TABLE rate_limits (
  bucket CHAR(64) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
