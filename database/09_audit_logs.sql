-- ============================================================================
-- Migration: 09_audit_logs.sql
-- Module: Security Compliance & System Action Trail
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) DEFAULT NULL,
  `actor_role` enum('Admin','Doctor','Staff','Patient','System') NOT NULL,
  `action_name` varchar(100) NOT NULL,
  `target_entity` varchar(150) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '127.0.0.1',
  `security_level` enum('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_audit_actor` (`actor_id`),
  KEY `idx_audit_level` (`security_level`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_actor` 
    FOREIGN KEY (`actor_id`) REFERENCES `users` (`user_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
