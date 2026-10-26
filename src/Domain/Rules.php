<?php

/**
 * What the checks need to know that the record doesn't say: the site's settings and core's code types.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Domain;

final class Rules
{
    /**
     * @param list<string>                                       $serviceTypes        code types that carry a fee and aren't diagnoses (code_types.ct_fee = 1, ct_diag = 0)
     * @param list<string>                                       $justifiedTypes      code types whose lines need a diagnosis pointer (code_types.ct_just set)
     * @param array<string, array{type: string, code: string}>    $vaccines            the clinic's vaccine list: CVX => the product line it bills
     * @param list<array{type: string, code: string}>            $administration      lines that bill giving a vaccine
     * @param list<array{type: string, from: string, to: string}> $visitLevel          visit codes modifier 25 attaches to
     * @param list<int>                                          $neverBillable       visit categories that are never billed (a phone note, a chart update)
     * @param int                                                $daysToBill          how long after a visit before "seen and not billed" counts
     * @param bool                                               $checkNdc            whether check 8 runs (a site billing no payer that needs NDCs turns it off)
     */
    public function __construct(
        public readonly array $serviceTypes,
        public readonly array $justifiedTypes,
        public readonly array $vaccines,
        public readonly array $administration,
        public readonly array $visitLevel,
        public readonly array $neverBillable,
        public readonly int $daysToBill = 3,
        public readonly bool $checkNdc = true,
    ) {
    }

    public function isService(Line $line): bool
    {
        return in_array($line->codeType, $this->serviceTypes, true);
    }

    public function isAdministration(Line $line): bool
    {
        foreach ($this->administration as $a) {
            if ($a['type'] === $line->codeType && strcasecmp($a['code'], $line->code) === 0) {
                return true;
            }
        }
        return false;
    }

    public function isVaccineProduct(Line $line): bool
    {
        foreach ($this->vaccines as $product) {
            if ($product['type'] === $line->codeType && strcasecmp($product['code'], $line->code) === 0) {
                return true;
            }
        }
        return false;
    }

    public function isVisitLevel(Line $line): bool
    {
        foreach ($this->visitLevel as $r) {
            if ($r['type'] === $line->codeType && strcmp(strtoupper($line->code), $r['from']) >= 0 && strcmp(strtoupper($line->code), $r['to']) <= 0
                && strlen($line->code) === strlen($r['from'])) {
                return true;
            }
        }
        return false;
    }
}
