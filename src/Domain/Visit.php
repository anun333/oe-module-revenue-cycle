<?php

/**
 * One visit as the checks see it: its charge lines, and what the record holds beside them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Domain;

final class Visit
{
    /**
     * @param list<Line>                                $lines          the fee sheet's active lines (billing.activity = 1)
     * @param list<string>                              $vaccinesGiven  CVX codes given here on the visit (not history entries or refusals)
     * @param list<array{code: string, resulted: bool}> $inClinicTests  orders from the site's in-clinic provider, by standard code
     */
    public function __construct(
        public readonly int $encounter,
        public readonly int $pid,
        public readonly string $date,
        public readonly int $categoryId,
        public readonly string $sensitivity,
        public readonly int $facilityId,
        public readonly int $providerId,
        public readonly bool $documented,
        public readonly bool $claimed,
        public readonly array $lines,
        public readonly array $vaccinesGiven = [],
        public readonly array $inClinicTests = [],
    ) {
    }
}
