<?php

/**
 * Checks 1 to 8 of the charge review (docs/products/revenue-cycle/charge-review-plan-2026-10.md in the openhealthcare
 * workspace), on one visit at a time. Each missed-charge check has a mirror for a charge with nothing behind it, so
 * both directions are found from the first version. Nothing here needs a licensed table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Domain;

final class ChargeChecks
{
    public function __construct(private readonly Rules $rules)
    {
    }

    /** @return list<Flag> */
    public function visit(Visit $v, \DateTimeImmutable $today): array
    {
        if (in_array($v->categoryId, $this->rules->neverBillable, true)) {
            return [];
        }
        $flags = [];
        $services = array_values(array_filter($v->lines, $this->rules->isService(...)));

        // 1. Seen and not billed: documented, past the days the clinic allows to code, and no service line.
        $age = (int)$today->diff(new \DateTimeImmutable($v->date))->format('%a');
        if ($v->documented && $services === [] && $age >= $this->rules->daysToBill) {
            $flags[] = new Flag(Flag::SEEN_NOT_BILLED, $v->encounter, $v->pid, 'visit', [(string)$age]);
        }

        // 2. A vaccine given and not billed: the product the clinic bills for it, and some administration line.
        foreach (array_unique($v->vaccinesGiven) as $cvx) {
            $product = $this->rules->vaccines[$cvx] ?? null;
            if ($product !== null && !self::hasLine($services, $product['type'], $product['code'])) {
                $flags[] = new Flag(Flag::VACCINE_NO_PRODUCT, $v->encounter, $v->pid, "cvx:$cvx", [$cvx, $product['code']]);
            }
        }
        $administered = array_values(array_filter($services, $this->rules->isAdministration(...)));
        if ($v->vaccinesGiven !== [] && $administered === []) {
            $flags[] = new Flag(Flag::VACCINE_NO_ADMINISTRATION, $v->encounter, $v->pid, 'administration', [(string)count(array_unique($v->vaccinesGiven))]);
        }

        // 3. A test done in the clinic, resulted, with no line for its code.
        foreach ($v->inClinicTests as $test) {
            if ($test['resulted'] && $test['code'] !== '' && !self::hasCode($services, $test['code'])) {
                $flags[] = new Flag(Flag::TEST_NOT_BILLED, $v->encounter, $v->pid, "test:{$test['code']}", [$test['code']]);
            }
        }

        // 4. Billed with nothing behind it: the mirrors of 1 to 3.
        if ($v->vaccinesGiven === []) {
            foreach ($services as $line) {
                if ($this->rules->isVaccineProduct($line) || $this->rules->isAdministration($line)) {
                    $flags[] = new Flag(Flag::VACCINE_BILLED_NONE_GIVEN, $v->encounter, $v->pid, "line:{$line->codeType}:{$line->code}", [$line->code], [$line->id]);
                }
            }
        }
        foreach ($services as $line) {
            $ordered = array_values(array_filter($v->inClinicTests, fn(array $t): bool => strcasecmp($t['code'], $line->code) === 0));
            if ($ordered !== [] && !in_array(true, array_column($ordered, 'resulted'), true)) {
                $flags[] = new Flag(Flag::TEST_BILLED_NO_RESULT, $v->encounter, $v->pid, "line:{$line->codeType}:{$line->code}", [$line->code], [$line->id]);
            }
        }
        if (!$v->documented && $services !== []) {
            $flags[] = new Flag(Flag::CHARGES_NO_DOCUMENTATION, $v->encounter, $v->pid, 'visit', [(string)count($services)], array_map(fn(Line $l): int => $l->id, $services));
        }

        // 5. The same code twice: two lines with one code and modifier, where units would carry the count.
        $groups = [];
        foreach ($services as $line) {
            $groups[strtoupper("{$line->codeType}|{$line->code}|{$line->modifier}")][] = $line;
        }
        foreach ($groups as $group => $lines) {
            if (count($lines) > 1) {
                $flags[] = new Flag(Flag::SAME_CODE_TWICE, $v->encounter, $v->pid, "group:$group", [$lines[0]->code, (string)count($lines)], array_map(fn(Line $l): int => $l->id, $lines));
            }
        }

        // 6. Modifier 25 on a visit code with no other service beside it.
        foreach ($services as $line) {
            if ($line->hasModifier('25') && $this->rules->isVisitLevel($line)) {
                $others = array_filter($services, fn(Line $o): bool => $o->id !== $line->id && !$this->rules->isVisitLevel($o));
                if ($others === []) {
                    $flags[] = new Flag(Flag::MODIFIER_25_ALONE, $v->encounter, $v->pid, "line:{$line->id}", [$line->code], [$line->id]);
                }
            }
        }

        // 7. A charge of a code type that needs a diagnosis pointer, with none.
        foreach ($services as $line) {
            if (in_array($line->codeType, $this->rules->justifiedTypes, true) && trim($line->justify, " :\t") === '') {
                $flags[] = new Flag(Flag::NO_DIAGNOSIS, $v->encounter, $v->pid, "line:{$line->id}", [$line->code], [$line->id]);
            }
        }

        // 8. A drug billed by its J code with no NDC the claim would carry. Core's fee sheet asks for an NDC on HCPCS
        // lines; Medicaid programs need it on physician-administered drugs to claim rebates (Social Security Act
        // 1927(a)(7)), and other payers often deny the line without it. A site that bills no such payer turns it off.
        if ($this->rules->checkNdc) {
            foreach ($services as $line) {
                if ($line->codeType === 'HCPCS' && stripos($line->code, 'J') === 0 && !$line->hasNdc()) {
                    $flags[] = new Flag(Flag::J_CODE_NO_NDC, $v->encounter, $v->pid, "line:{$line->id}", [$line->code], [$line->id]);
                }
            }
        }
        return $flags;
    }

    /** @param list<Line> $lines */
    private static function hasLine(array $lines, string $type, string $code): bool
    {
        foreach ($lines as $line) {
            if ($line->codeType === $type && strcasecmp($line->code, $code) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param list<Line> $lines */
    private static function hasCode(array $lines, string $code): bool
    {
        foreach ($lines as $line) {
            if (strcasecmp($line->code, $code) === 0) {
                return true;
            }
        }
        return false;
    }
}
