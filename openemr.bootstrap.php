<?php

/**
 * Revenue Cycle module bootstrap. OpenEMR loads it on every request while the module is enabled, the
 * login page included, so nothing here may stop a page: whatever the module needs that is missing,
 * it returns and the module stays off.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\RevenueCycle\Bootstrap;

// OEGlobalsBag's getProjectDir() came with OpenEMR 8.1.0; 7.0.4 to 8.0.x have the bag without it, and every
// release keeps the project directory in $GLOBALS['fileroot'] (from 7.0.4 the bag copies it there).
$projectDir = method_exists('OpenEMR\\Core\\OEGlobalsBag', 'getProjectDir') ? \OpenEMR\Core\OEGlobalsBag::getInstance()->getProjectDir() : (string)($GLOBALS['fileroot'] ?? '');
$classLoader = new ModulesClassLoader($projectDir);
$classLoader->registerNamespaceIfNotExists('OpenHealthcare\\RevenueCycle\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

// openhealthcare-common: in OpenEMR's vendor when Composer installed this module; in this module's own
// when it was copied in and `composer install --no-dev` run in its folder. Only its own folder is
// registered, never this module's whole vendor autoload, which could clash with OpenEMR's; and
// registering doesn't replace OpenEMR's own mapping when that exists. Register before asking whether
// the class exists: Composer remembers a class it once failed to find, and a later registration
// wouldn't be consulted.
$common = __DIR__ . '/vendor/anun333/openhealthcare-common/src';
if (is_dir($common)) {
    $classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Common\\', $common);
}
if (!class_exists(OpenEmr::class)) {
    return;
}

try {
    (new Bootstrap(OpenEmr::eventDispatcher()))->subscribeToEvents();
} catch (\RuntimeException) {
    // No kernel (a command-line script): nothing to subscribe to.
}
