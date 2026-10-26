<?php

/**
 * One fee sheet line (a billing row).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Domain;

final class Line
{
    public function __construct(
        public readonly int $id,
        public readonly string $codeType,
        public readonly string $code,
        public readonly string $modifier,
        public readonly int $units,
        public readonly float $fee,
        public readonly string $justify,
        public readonly string $ndcInfo = '',
    ) {
    }

    /**
     * Whether the line carries an NDC the claim will send: billing.ndc_info in the N4 format the fee sheet writes
     * ("N4" + the number + spaces + the unit + the quantity). Core's Claim::cptNDCID() reads only that format, the
     * same on 8.4.1 and master, so anything else in the field goes out with no NDC.
     */
    public function hasNdc(): bool
    {
        return preg_match('/^N4(\S+)\s+(\S\S)/', $this->ndcInfo) === 1;
    }

    /** Whether a modifier field (OpenEMR keeps up to four, colon-separated) holds this one. */
    public function hasModifier(string $modifier): bool
    {
        return in_array($modifier, array_map('trim', preg_split('/[:\s,]+/', strtoupper($this->modifier)) ?: []), true);
    }
}
