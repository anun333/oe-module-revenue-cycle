<?php

/**
 * Called by the Module Manager on install, enable, disable and similar actions.
 *
 * Install SQL is in sql/install.sql and is run by the Module Manager itself. Uninstalling leaves
 * the tables in place, for the clinic to drop if it wants.
 *
 * @package   OpenHealthcare\RevenueCycle
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenHealthcare\\RevenueCycle\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private function enable($modId, $currentActionStatus): mixed
    {
        self::setModuleState($modId, '1', '0');
        return $currentActionStatus;
    }

    private function disable($modId, $currentActionStatus): mixed
    {
        self::setModuleState($modId, '0', '0');
        return $currentActionStatus;
    }

    private static function setModuleState(int|string $modId, string $flag, string $flagUi): void
    {
        QueryUtils::sqlStatementThrowException(
            "UPDATE `modules` SET `mod_active` = ?, `mod_ui_active` = ? WHERE `mod_id` = ? OR `mod_directory` = ?",
            [$flag, $flagUi, $modId, $modId],
        );
    }
}
