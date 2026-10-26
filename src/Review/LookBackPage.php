<?php

/**
 * The look-back page's list: the findings narrowed to one check if one is chosen, a page of them at a time, and the
 * same findings as CSV rows. A year of a busy clinic's billed visits can hold tens of thousands of findings, which
 * as one page is many megabytes of HTML; the counts above the list still cover all of them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Review;

use OpenHealthcare\RevenueCycle\Domain\Flag;

final class LookBackPage
{
    /** Rows on one page of the list. */
    public const SIZE = 200;

    /**
     * Findings for one check, or all of them when the check is 0.
     *
     * @template T of array{flag: Flag}
     * @param list<T> $found
     * @return list<T>
     */
    public static function forCheck(array $found, int $check): array
    {
        return $check === 0 ? $found : array_values(array_filter($found, fn(array $f): bool => $f['flag']->check() === $check));
    }

    /**
     * How many findings each check has, by check number.
     *
     * @param list<array{flag: Flag}> $found
     * @return array<int, int>
     */
    public static function byCheck(array $found): array
    {
        $n = [];
        foreach ($found as $f) {
            $c = $f['flag']->check();
            $n[$c] = ($n[$c] ?? 0) + 1;
        }
        ksort($n);
        return $n;
    }

    /**
     * One page of rows. A page past the end shows the last page, and a page before the first shows the first.
     *
     * @template T
     * @param list<T> $rows
     * @return array{rows: list<T>, page: int, pages: int, first: int, last: int, total: int}
     */
    public static function page(array $rows, int $page, int $size = self::SIZE): array
    {
        $total = count($rows);
        $pages = max(1, (int)ceil($total / $size));
        $page = min(max(1, $page), $pages);
        $slice = array_slice($rows, ($page - 1) * $size, $size);
        $first = $slice === [] ? 0 : ($page - 1) * $size + 1;
        return ['rows' => $slice, 'page' => $page, 'pages' => $pages, 'first' => $first, 'last' => $first === 0 ? 0 : $first + count($slice) - 1, 'total' => $total];
    }

    /**
     * A cell for the CSV file. A value starting with =, +, - or @ is kept as text with a leading apostrophe, so a
     * spreadsheet doesn't run it as a formula (a patient's name or a code typed into a record could start that way).
     */
    public static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
