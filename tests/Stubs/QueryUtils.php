<?php

/**
 * OpenEMR's QueryUtils for the unit tests, which run without OpenEMR: each call returns the next queued result
 * and keeps the SQL and binds it was given, so a test can say which rows a query asks for.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Common\Database;

final class QueryUtils
{
    /** @var list<list<array<string, mixed>>> */
    public static array $results = [];

    /** @var list<array{sql: string, binds: array<mixed>}> */
    public static array $calls = [];

    /**
     * @param array<mixed> $binds
     * @return list<array<string, mixed>>
     */
    public static function fetchRecords(string $sql, array $binds = []): array
    {
        self::$calls[] = ['sql' => $sql, 'binds' => $binds];
        return array_shift(self::$results) ?? [];
    }
}
