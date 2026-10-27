<?php

/**
 * One thing a check found on one visit. Missed: care in the record that no charge bills. Over: a charge the record
 * doesn't support. Quality: a line the claim would carry wrong. The flag says what was found, never which code to
 * add: that's the biller's call, and no flag ever asks for documentation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Domain;

final class Flag
{
    public const MISSED = 'missed';
    public const OVER = 'over';
    public const QUALITY = 'quality';

    public const SEEN_NOT_BILLED = 'seen_not_billed';
    public const SEEN_NO_VISIT = 'seen_no_visit';
    public const VACCINE_NO_PRODUCT = 'vaccine_no_product';
    public const VACCINE_NO_ADMINISTRATION = 'vaccine_no_administration';
    public const TEST_NOT_BILLED = 'test_not_billed';
    public const VACCINE_BILLED_NONE_GIVEN = 'vaccine_billed_none_given';
    public const TEST_BILLED_NO_RESULT = 'test_billed_no_result';
    public const CHARGES_NO_DOCUMENTATION = 'charges_no_documentation';
    public const SAME_CODE_TWICE = 'same_code_twice';
    public const MODIFIER_25_ALONE = 'modifier_25_alone';
    public const NO_DIAGNOSIS = 'no_diagnosis';
    public const J_CODE_NO_NDC = 'j_code_no_ndc';
    public const REFERRER_NO_NPI = 'referrer_no_npi';

    /** Each kind's check number in the plan, and its direction. */
    public const KINDS = [
        self::SEEN_NOT_BILLED => [1, self::MISSED], self::SEEN_NO_VISIT => [1, self::MISSED], self::VACCINE_NO_PRODUCT => [2, self::MISSED],
        self::VACCINE_NO_ADMINISTRATION => [2, self::MISSED], self::TEST_NOT_BILLED => [3, self::MISSED],
        self::VACCINE_BILLED_NONE_GIVEN => [4, self::OVER], self::TEST_BILLED_NO_RESULT => [4, self::OVER],
        self::CHARGES_NO_DOCUMENTATION => [4, self::OVER], self::SAME_CODE_TWICE => [5, self::OVER],
        self::MODIFIER_25_ALONE => [6, self::OVER], self::NO_DIAGNOSIS => [7, self::QUALITY],
        self::J_CODE_NO_NDC => [8, self::QUALITY], self::REFERRER_NO_NPI => [9, self::QUALITY],
    ];

    /**
     * @param string       $subject what it's about, stable across runs (a CVX, a code, a line id), so the flag keeps its key
     * @param list<string> $args    the values its sentence names
     * @param list<int>    $lineIds the fee sheet lines it points at
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $encounter,
        public readonly int $pid,
        public readonly string $subject,
        public readonly array $args = [],
        public readonly array $lineIds = [],
    ) {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException("unknown flag kind $kind");
        }
    }

    public function check(): int
    {
        return self::KINDS[$this->kind][0];
    }

    public function direction(): string
    {
        return self::KINDS[$this->kind][1];
    }

    public const SOURCE = 'revcycle';

    /**
     * The flag's ref in Staff Worklist keys, which take a number: 18 digits from a hash of the visit, the kind and
     * what it's about, so the same finding on the same visit keeps its key across runs (revenue_cycle_flag maps it back).
     */
    public function ref(): string
    {
        // Digits taken from the hash as text, so the ref is the same on 32-bit PHP, where an 18-digit number would
        // be a float ("9.9E+16"), which the Worklist's key pattern refuses.
        $hash = hash('sha256', "{$this->encounter}|{$this->kind}|{$this->subject}");
        $digits = '';
        for ($i = 0; $i < 17; $i++) {
            $digits .= (string)(hexdec($hash[$i]) % 10);
        }
        return '1' . $digits;
    }

    /** source:pid:ref, as the Staff Worklist keys an item. */
    public function key(): string
    {
        return self::SOURCE . ":{$this->pid}:" . $this->ref();
    }
}
