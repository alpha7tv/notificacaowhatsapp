CREATE TABLE IF NOT EXISTS app_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  device_key VARCHAR(64) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  model VARCHAR(120) NULL,
  app_version VARCHAR(20) NULL,
  ip VARCHAR(45) NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  UNIQUE KEY uq_app_device (client_id, device_key),
  KEY idx_app_device_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
