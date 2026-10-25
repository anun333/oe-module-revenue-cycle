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
