CREATE TABLE IF NOT EXISTS nurture_broadcast_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  broadcast_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NOT NULL,
  phone_e164 VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_nbi_status (status, id),
  KEY idx_nbi_broadcast (broadcast_id),
  KEY idx_nbi_sent (status, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
