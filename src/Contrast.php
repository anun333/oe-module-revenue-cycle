<?php

/**
 * OpenEMR's Bootstrap primary (#007bff) is 3.97:1 on white, under the 4.5:1 WCAG 2.1 AA asks of
 * normal text (HHS's Section 504 rule, 45 CFR 84, from 2027-05-11). On this module's own pages, and
 * only under the themes that draw it on white (light and manila, their right-to-left builds, and
 * compact mode on top of them), its uses take #0056b3 instead, Bootstrap's own hover shade: 7.0:1
 * on white. The muted grey and the danger red take #4b5563 and #b02a37, which stay above 4.5:1 on
 * the tinted rows and warning cells (#f3f4f6, #ffeeba) where the theme's own fall short. Dark,
 * solar and the colour themes keep their own colours. A link inside text is underlined: the darker
 * blue is 2.5:1 against the body text, under the 3:1 that lets colour alone mark a link.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle;

use OpenHealthcare\Common\OpenEmr;

final class Contrast
{
    /** The themes that draw Bootstrap's #007bff on a light background (public/themes, 2026-10-26). */
    public const THEMES = ['style_light.css', 'style_manila.css'];

    public const CSS = 'a{color:#0056b3}a:hover,a:focus{color:#004085}.btn-link{color:#0056b3}.btn-link:hover,.btn-link:focus{color:#004085}.text-primary{color:#0056b3!important}a.text-primary:hover,a.text-primary:focus{color:#004085!important}.btn-primary,.btn-primary.disabled,.btn-primary:disabled{color:#fff;background-color:#0056b3;border-color:#0056b3}.btn-primary:hover,.btn-primary:focus,.btn-primary.focus{color:#fff;background-color:#004085;border-color:#004085}.btn-primary:not(:disabled):not(.disabled):active,.btn-primary:not(:disabled):not(.disabled).active,.show>.btn-primary.dropdown-toggle{color:#fff;background-color:#003a75;border-color:#003a75}.btn-outline-primary,.btn-outline-primary.disabled,.btn-outline-primary:disabled{color:#0056b3;border-color:#0056b3}.btn-outline-primary:hover,.btn-outline-primary:not(:disabled):not(.disabled):active,.btn-outline-primary:not(:disabled):not(.disabled).active,.show>.btn-outline-primary.dropdown-toggle{color:#fff;background-color:#0056b3;border-color:#0056b3}.badge-primary{color:#fff;background-color:#0056b3}.bg-primary{background-color:#0056b3!important}.border-primary{border-color:#0056b3!important}.page-link{color:#0056b3}.page-link:hover,.page-link:focus{color:#004085}.page-item.active .page-link{color:#fff;background-color:#0056b3;border-color:#0056b3}.nav-pills .nav-link.active,.nav-pills .show>.nav-link{color:#fff;background-color:#0056b3}.list-group-item.active{color:#fff;background-color:#0056b3;border-color:#0056b3}.dropdown-item.active,.dropdown-item:active{color:#fff;background-color:#0056b3}.custom-control-input:checked~.custom-control-label::before{background-color:#0056b3;border-color:#0056b3}.progress-bar{background-color:#0056b3}.text-muted{color:#4b5563!important}.text-danger{color:#b02a37!important}:is(p,li,td,dd,small,.form-text) a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link):not(.list-group-item){text-decoration:underline}';

    /**
     * The style to print after Header::setupHeader(), or nothing under another theme.
     *
     * @param ?string $cssHeader OpenEMR's css_header setting (a theme file's URL); read from OpenEMR when null
     */
    public static function style(?string $cssHeader = null): string
    {
        return self::applies($cssHeader ?? OpenEmr::setting('css_header')) ? '<style>' . self::CSS . '</style>' : '';
    }

    /** Whether this theme setting is one of THEMES; none set means OpenEMR's default, light. */
    public static function applies(string $cssHeader): bool
    {
        $file = basename((string)parse_url($cssHeader, PHP_URL_PATH));
        $file = (string)preg_replace('/^rtl_/', '', $file);
        return $file === '' || in_array($file, self::THEMES, true);
    }
}
