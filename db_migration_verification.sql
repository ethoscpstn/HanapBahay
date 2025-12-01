-- Database Migration for Property Listing Verification System
-- Run this SQL script in your phpMyAdmin or MySQL client

-- Add new columns to tblistings table
ALTER TABLE `tblistings`
ADD COLUMN `gov_id_path` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Path to government ID file' AFTER `amenities`,
ADD COLUMN `property_photos` TEXT NULL DEFAULT NULL COMMENT 'JSON array of property photo paths' AFTER `gov_id_path`,
ADD COLUMN `verification_status` VARCHAR(20) NULL DEFAULT NULL COMMENT 'pending, approved, rejected' AFTER `property_photos`,
ADD COLUMN `rejection_reason` TEXT NULL DEFAULT NULL COMMENT 'Reason for rejection if status is rejected' AFTER `verification_status`;

-- Update existing listings to have NULL verification_status (they'll be treated as approved)
-- This ensures existing listings remain visible without requiring verification
UPDATE `tblistings` SET `verification_status` = NULL WHERE `verification_status` IS NULL;

-- Optional: Set existing verified listings to 'approved' status
-- Uncomment the next line if you want to explicitly mark existing verified listings
-- UPDATE `tblistings` SET `verification_status` = 'approved' WHERE `is_verified` = 1 AND `verification_status` IS NULL;

-- Create an index for faster filtering
CREATE INDEX `idx_verification_status` ON `tblistings` (`verification_status`);
CREATE INDEX `idx_is_verified_archived` ON `tblistings` (`is_verified`, `is_archived`);
