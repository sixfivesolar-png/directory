-- For an existing installation created before the user and moderation features.
USE prachuap_directory;
ALTER TABLE reviews ADD COLUMN IF NOT EXISTS user_id BIGINT UNSIGNED NULL AFTER place_slug;
ALTER TABLE reviews ADD COLUMN IF NOT EXISTS status ENUM('pending','approved','hidden') NOT NULL DEFAULT 'pending' AFTER comment;
ALTER TABLE reviews ADD COLUMN IF NOT EXISTS moderated_by BIGINT UNSIGNED NULL AFTER status;
ALTER TABLE reviews ADD COLUMN IF NOT EXISTS moderated_at TIMESTAMP NULL AFTER moderated_by;

