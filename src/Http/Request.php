<?php

/**
 * The current HTTP request. OpenEMR 8.4 added CurrentRequest, one request object per
 * process; on 8.1 to 8.3, which don't have it, the request is built from the globals
 * the same way CurrentRequest builds it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Http;

use OpenEMR\Common\Http\CurrentRequest;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

final class Request
{
    private static ?SymfonyRequest $fallback = null;

    public static function get(): SymfonyRequest
    {
        if (class_exists(CurrentRequest::class)) {
            return CurrentRequest::get();
        }
        return self::$fallback ??= SymfonyRequest::createFromGlobals();
    }

    /**
     * A field as text: '' when it is missing or isn't one value. InputBag::getString() does this from
     * Symfony 6.3, and OpenEMR 7.0.x ships 5.4, where calling it stops the page.
     */
    /** @param array<array-key, mixed> $fields a bag's fields: $request->query->all() */
    public static function text(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }
}
