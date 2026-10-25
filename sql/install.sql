--
-- Revenue Cycle: install. Every statement is inside an #If...#EndIf guard, so running it again changes
-- nothing (tools/footprint.php checks that). Later versions add sql/X_Y_Z-to-A_B_C_upgrade.sql with
-- the same guarded blocks; Module Manager offers an upgrade when one's from-version is at or after the
-- installed one.
--

-- The module's settings, by name. Rename it, or replace it with the tables the module needs.
#IfNotTable revenue_cycle_setting
CREATE TABLE `revenue_cycle_setting` (
    `name` VARCHAR(64) NOT NULL,
    `value` TEXT,
    `updated_by` INT(11) NOT NULL COMMENT 'users.id who saved it',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB COMMENT='Revenue Cycle: settings';
#EndIf

-- Each flag a check has found, so the report can count both directions: accepted (the condition cleared, by a fee
-- sheet change) and dismissed (in the Staff Worklist, with its reason). Keyed as the Worklist keys the item.
#IfNotTable revenue_cycle_flag
CREATE TABLE `revenue_cycle_flag` (
    `item_key` VARCHAR(64) NOT NULL COMMENT 'revcycle:<pid>:<ref>, the Staff Worklist item key',
    `pid` BIGINT(20) NOT NULL,
    `encounter` BIGINT(20) NOT NULL COMMENT '0 for an appointment seen with no visit',
    `kind` VARCHAR(32) NOT NULL,
    `check_no` TINYINT NOT NULL,
    `direction` VARCHAR(8) NOT NULL COMMENT 'missed, over or quality',
    `subject` VARCHAR(191) NOT NULL,
    `first_seen` DATETIME NOT NULL,
    `last_seen` DATETIME NOT NULL,
    `cleared_at` DATETIME DEFAULT NULL COMMENT 'when a check no longer found it',
    PRIMARY KEY (`item_key`),
    KEY `idx_pid` (`pid`),
    KEY `idx_encounter` (`encounter`)
) ENGINE=InnoDB COMMENT='Revenue Cycle: each charge check finding, and when it cleared';
#EndIf
