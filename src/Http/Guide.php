<?php

/**
 * Where the staff guide is: GUIDE.md at the installed version's tag, so a clinic on 0.1.4 reads the guide written
 * for 0.1.4. The pages link to it as Help, opening a new window, since GitHub won't load inside OpenEMR's frame.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Http;

final class Guide
{
    private const REPOSITORY = 'https://github.com/anun333/oe-module-revenue-cycle';

    /** The guide at this module's version tag; the main branch's when version.php can't be read. */
    public static function url(string $versionFile = __DIR__ . '/../../version.php'): string
    {
        $v_major = $v_minor = $v_patch = null;
        if (is_file($versionFile)) {
            include $versionFile;
        }
        $version = implode('.', [$v_major, $v_minor, $v_patch]);
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1
            ? self::REPOSITORY . '/blob/v' . $version . '/GUIDE.md'
            : self::REPOSITORY . '/blob/main/GUIDE.md';
    }
}
