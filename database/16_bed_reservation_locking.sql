-- ============================================================================
-- MedPulse Enterprise Hospital Management System
-- Migration 16: Optimistic Bed Locking & Anti-Double-Booking
-- ============================================================================
-- Safe, idempotent, non-destructive ALTER statements.
-- Adds temporary-reservation columns to hospital_beds and a
-- per-patient "one active booking" enforcement index.
-- ============================================================================

USE `medpulse_hms`;

-- ----------------------------------------------------------------------------
-- 1. Add reservation columns to hospital_beds
--    reserved_until        : when the temporary lock expires (10-minute window)
--    reservation_user_id   : which patient user currently holds the soft lock
--    reservation_token     : random hex fingerprint so only the holder can release
-- ----------------------------------------------------------------------------
ALTER TABLE `hospital_beds`
  ADD COLUMN IF NOT EXISTS `reserved_until`      DATETIME     NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `reservation_user_id` INT(11)      NULL DEFAULT NULL AFTER `reserved_until`,
  ADD COLUMN IF NOT EXISTS `reservation_token`   VARCHAR(64)  NULL DEFAULT NULL AFTER `reservation_user_id`;

-- Index for fast expiry sweeps and user lookups
ALTER TABLE `hospital_beds`
  ADD INDEX IF NOT EXISTS `idx_beds_reservation` (`reservation_user_id`, `reserved_until`),
  ADD INDEX IF NOT EXISTS `idx_beds_reserved_until` (`reserved_until`);

-- Optional FK: reservation_user_id -> users(user_id), SET NULL on delete
SET @add_fk_res = (SELECT IF(
    NOT EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'hospital_beds'
          AND CONSTRAINT_NAME = 'fk_beds_reservation_user'
    ),
    'ALTER TABLE `hospital_beds` ADD CONSTRAINT `fk_beds_reservation_user` FOREIGN KEY (`reservation_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;',
    'SELECT 1;'
));
PREPARE stmt_fk_res FROM @add_fk_res;
EXECUTE stmt_fk_res;
DEALLOCATE PREPARE stmt_fk_res;

-- ----------------------------------------------------------------------------
-- 2. Add reservation columns to bed_allocations for pending (pre-confirmation) requests
--    request_status tracks the lifecycle: pending → confirmed | cancelled | expired
-- ----------------------------------------------------------------------------
ALTER TABLE `bed_allocations`
  ADD COLUMN IF NOT EXISTS `request_status`   ENUM('pending','confirmed','cancelled','expired') NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `reservation_token` VARCHAR(64) NULL DEFAULT NULL AFTER `request_status`,
  ADD COLUMN IF NOT EXISTS `expires_at`        DATETIME   NULL DEFAULT NULL AFTER `reservation_token`;

ALTER TABLE `bed_allocations`
  ADD INDEX IF NOT EXISTS `idx_ba_request_status` (`request_status`),
  ADD INDEX IF NOT EXISTS `idx_ba_expires_at`      (`expires_at`),
  ADD INDEX IF NOT EXISTS `idx_ba_reservation_token` (`reservation_token`);

-- ----------------------------------------------------------------------------
-- 3. Scheduled Event: Release expired reservations (requires event_scheduler=ON)
--    If the event scheduler is disabled, expiry is handled by the PHP cleanup
--    endpoint (backend/api/bed_reservation.php action=cleanup) which should
--    be called via cron: * * * * * curl -s http://localhost/MedPulse/backend/api/bed_reservation.php?action=cleanup
-- ----------------------------------------------------------------------------
SET @ev_sql = (SELECT IF(
    (SELECT @@global.event_scheduler) IN ('ON','1'),
    "CREATE OR REPLACE EVENT `evt_release_expired_bed_reservations`
       ON SCHEDULE EVERY 60 SECOND STARTS NOW()
       ON COMPLETION PRESERVE ENABLE
       COMMENT 'Auto-releases expired temporary bed reservations'
       DO BEGIN
         UPDATE `bed_allocations` SET `request_status` = 'expired'
         WHERE `request_status` = 'pending' AND `expires_at` IS NOT NULL AND `expires_at` < NOW();
         UPDATE `hospital_beds`
         SET `status` = 'Available', `reserved_until` = NULL, `reservation_user_id` = NULL, `reservation_token` = NULL
         WHERE `status` = 'Reserved' AND `reserved_until` IS NOT NULL AND `reserved_until` < NOW();
       END",
    "SELECT 'event_scheduler is OFF; PHP cron will handle cleanup' AS note"
));
PREPARE stmt_ev FROM @ev_sql;
EXECUTE stmt_ev;
DEALLOCATE PREPARE stmt_ev;

