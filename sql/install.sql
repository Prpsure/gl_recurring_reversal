-- ============================================================
-- gl_recurring_reversal — install.sql (idempotent)
-- Literal prefix 0_ is rewritten by FA db_import to company TB_PREF.
-- Do NOT DROP tables here (preserves data on re-activate).
-- ============================================================

CREATE TABLE IF NOT EXISTS `0_gl_recurring_def` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `frequency` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=Monthly',
  `next_due_date` date NOT NULL,
  `memo_` varchar(255) NOT NULL DEFAULT '',
  `currency` char(3) NOT NULL DEFAULT '',
  `rate` double NOT NULL DEFAULT 1,
  `inactive` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(60) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `next_due_date` (`next_due_date`),
  KEY `inactive_due` (`inactive`, `next_due_date`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `0_gl_recurring_def_line` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `def_id` int(11) NOT NULL,
  `account` varchar(15) NOT NULL DEFAULT '',
  `dimension_id` int(11) NOT NULL DEFAULT 0,
  `dimension2_id` int(11) NOT NULL DEFAULT 0,
  `memo_` tinytext NOT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `person_type_id` int(11) DEFAULT NULL,
  `person_id` tinyblob,
  `line_no` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `def_id` (`def_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `0_gl_reversal_link` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_type` smallint(6) NOT NULL DEFAULT 0,
  `original_trans_no` int(11) NOT NULL,
  `reversal_type` smallint(6) NOT NULL DEFAULT 0,
  `reversal_trans_no` int(11) NOT NULL,
  `reversal_date` date NOT NULL,
  `created_by` varchar(60) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `original_unique` (`original_type`, `original_trans_no`),
  KEY `reversal_ref` (`reversal_type`, `reversal_trans_no`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `0_gl_rr_run` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_type` varchar(16) NOT NULL,
  `user_id` varchar(60) NOT NULL,
  `run_at` datetime NOT NULL,
  `selected_count` int(11) NOT NULL DEFAULT 0,
  `posted_count` int(11) NOT NULL DEFAULT 0,
  `skipped_count` int(11) NOT NULL DEFAULT 0,
  `memo_` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_at` (`run_at`),
  KEY `run_type` (`run_type`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `0_gl_rr_run_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `source_type` smallint(6) DEFAULT NULL,
  `source_trans_no` int(11) DEFAULT NULL,
  `source_def_id` int(11) DEFAULT NULL,
  `source_date` date DEFAULT NULL,
  `amount` double DEFAULT NULL,
  `new_trans_no` int(11) DEFAULT NULL,
  `status` varchar(16) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `rule_id` varchar(8) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_id` (`run_id`)
) ENGINE=InnoDB;
