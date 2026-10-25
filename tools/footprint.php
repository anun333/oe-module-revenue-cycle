<?php

/**
 * Writes FOOTPRINT.md with openhealthcare-common's footprint tool, the one every openhealthcare
 * module shares. What this module's sheet can't show is in composer.json, under
 * extra.openhealthcare.footprint-not-covered.
 *
 *     php tools/footprint.php            write FOOTPRINT.md
 *     php tools/footprint.php --check    exit 1 if FOOTPRINT.md is stale
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Composer installs the package under the web root; the tools are for the command line only.
    http_response_code(404);
    exit;
}
$tool = dirname(__DIR__) . '/vendor/anun333/openhealthcare-common/tools/footprint.php';
if (!is_file($tool)) {
    fwrite(STDERR, "footprint: run composer install first; the tool comes with anun333/openhealthcare-common 1.1 or later\n");
    exit(2);
}
$argv[] = '--root=' . dirname(__DIR__);
require $tool;
