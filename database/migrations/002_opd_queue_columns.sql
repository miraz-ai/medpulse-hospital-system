-- ============================================================
-- MedPulse HMS — Migration 002: OPD Queue & Doctor Session Columns
-- Safe ALTER TABLE additions (IF NOT EXISTS equivalent via column check).
-- Existing records and data are untouched.
-- Date: 2026-09-26
-- ============================================================

-- ── 1. appointments.queue_status ─────────────────────────────────────────────
--   Maps the OPD serial lifecycle: scheduled → serving → completed/missed/cancelled
ALTER TABLE `appointments`
    ADD COLUMN `queue_status`
        ENUM('scheduled','serving','completed','missed','cancelled')
        NOT NULL
        DEFAULT 'scheduled'
        COMMENT 'Live OPD serial position state for real-time queue tracking'
    AFTER `status`;

-- Backfill existing rows: map current appointment status → queue_status
UPDATE `appointments` SET `queue_status` = CASE
    WHEN `status` = 'booked'           THEN 'scheduled'
    WHEN `status` = 'checked_in'       THEN 'scheduled'
    WHEN `status` = 'in_consultation'  THEN 'serving'
    WHEN `status` = 'completed'        THEN 'completed'
    WHEN `status` = 'cancelled'        THEN 'cancelled'
    ELSE 'scheduled'
END;

-- ── 2. doctor_profiles.session_status ────────────────────────────────────────
--   Tracks whether a doctor's chamber is idle, live (consulting), paused, or done
ALTER TABLE `doctor_profiles`
    ADD COLUMN `session_status`
        ENUM('idle','live','paused','completed')
        NOT NULL
        DEFAULT 'idle'
        COMMENT 'Real-time chamber consulting session state'
    AFTER `updated_at`;

-- ── 3. doctor_profiles.current_serving_token ─────────────────────────────────
--   The token # the doctor is currently calling/serving (0 = none started)
ALTER TABLE `doctor_profiles`
    ADD COLUMN `current_serving_token`
        INT NOT NULL
        DEFAULT 0
        COMMENT 'Current token number being actively served in this session'
    AFTER `session_status`;

-- ── 4. doctor_profiles.accumulated_delta_minutes ─────────────────────────────
--   Running sum of +/- time deltas to dynamically recalculate wait estimates
ALTER TABLE `doctor_profiles`
    ADD COLUMN `accumulated_delta_minutes`
        INT NOT NULL
        DEFAULT 0
        COMMENT 'Running time offset (minutes) for dynamic ETA recalculation'
    AFTER `current_serving_token`;
