<?php

/**
 * The page's language and direction for `<html lang dir>`. openhealthcare-common 1.4.0 reads them
 * from the signed-in user's OpenEMR language. On a site where modules were copied in by hand, an
 * older copy of the library can be the one loaded first, so these fall back to English rather than
 * stop the page.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Http;

use OpenHealthcare\Common\OpenEmr;

final class Page
{
    public static function lang(): string
    {
        return method_exists(OpenEmr::class, 'pageLang') ? OpenEmr::pageLang() : 'en';
    }

    public static function dir(): string
    {
        return method_exists(OpenEmr::class, 'pageDir') ? OpenEmr::pageDir() : 'ltr';
    }
}
