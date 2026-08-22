-- Blue Coastal Atlas member place submissions: import once after the existing upgrades.
CREATE TABLE IF NOT EXISTS place_submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  category VARCHAR(80) NOT NULL,
  district VARCHAR(100) NOT NULL,
  subdistrict VARCHAR(100) NOT NULL,
  address_note VARCHAR(300) NOT NULL,
  contact_note VARCHAR(300) NOT NULL,
  hours_note VARCHAR(300) NOT NULL DEFAULT '',
  description VARCHAR(1500) NOT NULL,
  price_tier ENUM('unknown','budget','standard','premium') NOT NULL DEFAULT 'unknown',
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_place_slug VARCHAR(140) NULL UNIQUE,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_place_submissions_user_status (user_id,status,created_at),
  INDEX idx_place_submissions_status (status,created_at),
  CONSTRAINT fk_place_submissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_place_submissions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
