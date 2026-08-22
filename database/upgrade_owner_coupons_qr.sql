-- Run after upgrade_coupons_leaderboard.sql on an existing prachuap_directory database.
USE prachuap_directory;
ALTER TABLE coupons MODIFY status ENUM('pending_admin','active','inactive','rejected') NOT NULL DEFAULT 'pending_admin';
ALTER TABLE coupons ADD COLUMN owner_id BIGINT UNSIGNED NULL AFTER created_by;
ALTER TABLE coupons ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER owner_id;
ALTER TABLE coupons ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by;
ALTER TABLE coupons ADD COLUMN review_note VARCHAR(500) NOT NULL DEFAULT '' AFTER approved_at;
ALTER TABLE coupons ADD INDEX idx_coupons_owner_status (owner_id,status,created_at);
ALTER TABLE coupons ADD CONSTRAINT fk_coupon_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE coupons ADD CONSTRAINT fk_coupon_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE coupon_redemptions MODIFY status ENUM('issued','used','redeemed','cancelled') NOT NULL DEFAULT 'issued';
ALTER TABLE coupon_redemptions ADD COLUMN used_by BIGINT UNSIGNED NULL AFTER redeemed_at;
ALTER TABLE coupon_redemptions ADD COLUMN used_at TIMESTAMP NULL AFTER used_by;
ALTER TABLE coupon_redemptions ADD CONSTRAINT fk_redemption_used_by FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL;
