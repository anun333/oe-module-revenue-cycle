<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenHealthcare\RevenueCycle\Domain\ChargeChecks;
use OpenHealthcare\RevenueCycle\Domain\Flag;
use OpenHealthcare\RevenueCycle\Domain\Line;
use OpenHealthcare\RevenueCycle\Domain\Referrer;
use OpenHealthcare\RevenueCycle\Domain\Rules;
use OpenHealthcare\RevenueCycle\Domain\Visit;
use PHPUnit\Framework\TestCase;

/**
 * Each check finds what it should on a visit made for it, and nothing on the visit that's billed right. Every visit
 * here is made up; the CVX and code numbers are only the ones a clinic's own list would hold.
 */
final class ChargeChecksTest extends TestCase
{
    private ChargeChecks $checks;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $codes = json_decode((string)file_get_contents(dirname(__DIR__) . '/data/codes.json'), true);
        $this->checks = new ChargeChecks(new Rules(
            serviceTypes: ['CPT4', 'HCPCS'],
            justifiedTypes: ['CPT4', 'HCPCS'],
            vaccines: ['141' => ['type' => 'CPT4', 'code' => '90686'], '03' => ['type' => 'CPT4', 'code' => '90707']],
            administration: array_map(fn(array $c): array => ['type' => $c['type'], 'code' => $c['code']], $codes['vaccineAdministration']['codes']),
            visitLevel: $codes['visitLevel']['ranges'],
            neverBillable: [9],
            daysToBill: 3,
        ));
        $this->today = new \DateTimeImmutable('2026-10-20');
    }

    public function testAVisitBilledRightHasNothingToCheck(): void
    {
        $v = $this->visit(lines: [$this->line(1, '99213', '25'), $this->line(2, '90686'), $this->line(3, '90471'), $this->line(4, '87880')],
            vaccines: ['141'], tests: [['code' => '87880', 'resulted' => true]]);
        self::assertSame([], $this->kinds($v));
    }

    public function testCheck1FindsADocumentedVisitWithNoServiceLineOnceTheDaysHavePassed(): void
    {
        self::assertSame([Flag::SEEN_NOT_BILLED], $this->kinds($this->visit(date: '2026-10-15', lines: [$this->line(1, 'Z00.00', '', 'ICD10')])));
        self::assertSame([], $this->kinds($this->visit(date: '2026-10-19')), 'still within the days the clinic allows to code');
        self::assertSame([], $this->kinds($this->visit(date: '2026-10-15', category: 9)), 'a category that is never billed');
        self::assertSame([], $this->kinds($this->visit(date: '2026-10-15', documented: false)), 'nothing in the record');
    }

    public function testCheck2FindsAVaccineGivenWithNoProductOrNoAdministration(): void
    {
        $v = $this->visit(lines: [$this->line(1, '99213')], vaccines: ['141', '03']);
        self::assertSame([Flag::VACCINE_NO_PRODUCT, Flag::VACCINE_NO_PRODUCT, Flag::VACCINE_NO_ADMINISTRATION], $this->kinds($v));
        $flags = $this->checks->visit($v, $this->today);
        self::assertSame(['141', '90686'], $flags[0]->args, 'names the CVX and the product the clinic bills for it');
        self::assertSame([], $this->kinds($this->visit(lines: [$this->line(1, '99213'), $this->line(2, '90686'), $this->line(3, '90471')], vaccines: ['141', '88'])),
            'a CVX not on the clinic list names nothing, rather than guess a code');
    }

    public function testCheck3FindsAResultedInClinicTestWithNoLine(): void
    {
        $v = $this->visit(lines: [$this->line(1, '99213')], tests: [['code' => '87880', 'resulted' => true], ['code' => '81025', 'resulted' => false]]);
        self::assertSame([Flag::TEST_NOT_BILLED], $this->kinds($v), 'the unresulted one is not yet a charge');
    }

    public function testCheck4FindsChargesWithNothingBehindThem(): void
    {
        self::assertSame([Flag::VACCINE_BILLED_NONE_GIVEN, Flag::VACCINE_BILLED_NONE_GIVEN],
            $this->kinds($this->visit(lines: [$this->line(1, '99213'), $this->line(2, '90686'), $this->line(3, '90471')])));
        self::assertSame([Flag::TEST_BILLED_NO_RESULT],
            $this->kinds($this->visit(lines: [$this->line(1, '99213'), $this->line(2, '87880')], tests: [['code' => '87880', 'resulted' => false]])));
        self::assertSame([Flag::CHARGES_NO_DOCUMENTATION], $this->kinds($this->visit(documented: false, lines: [$this->line(1, '99213')])));
    }

    public function testCheck5FindsTheSameCodeTwiceButNotWithDifferentModifiers(): void
    {
        self::assertSame([Flag::SAME_CODE_TWICE], $this->kinds($this->visit(lines: [$this->line(1, '99213'), $this->line(2, '36415'), $this->line(3, '36415')])));
        self::assertSame([], $this->kinds($this->visit(lines: [$this->line(1, '99213'), $this->line(2, '11100', 'RT'), $this->line(3, '11100', 'LT')])));
    }

    public function testCheck6FindsModifier25OnAVisitWithNothingBesideIt(): void
    {
        self::assertSame([Flag::MODIFIER_25_ALONE], $this->kinds($this->visit(lines: [$this->line(1, '99214', '25')])));
        self::assertSame([Flag::MODIFIER_25_ALONE], $this->kinds($this->visit(lines: [$this->line(1, '99214', '25'), $this->line(2, '99396')])),
            'another visit code is not a separate service');
        self::assertSame([], $this->kinds($this->visit(lines: [$this->line(1, '99214', '25:59'), $this->line(2, '11100')])), 'the modifier among several, with a procedure');
        self::assertSame([], $this->kinds($this->visit(lines: [$this->line(1, '992140', '25')])), 'six digits is not the visit family');
    }

    public function testCheck7FindsAChargeWithNoDiagnosisPointer(): void
    {
        $flags = $this->checks->visit($this->visit(lines: [$this->line(1, '99213', '', 'CPT4', ''), $this->line(2, 'G0008', '', 'HCPCS', 'ICD10|Z23:')], vaccines: ['141']), $this->today);
        self::assertContains(Flag::NO_DIAGNOSIS, array_map(fn(Flag $f): string => $f->kind, $flags));
        self::assertSame([1], array_values(array_filter(array_map(fn(Flag $f): ?int => $f->kind === Flag::NO_DIAGNOSIS ? $f->lineIds[0] : null, $flags))));
        $emptied = $this->checks->visit($this->visit(lines: [$this->line(1, '99213', '', 'CPT4', ':')]), $this->today);
        self::assertSame([Flag::NO_DIAGNOSIS], array_map(fn(Flag $f): string => $f->kind, $emptied), 'a pointer emptied to its separator is still none');
    }

    public function testCheck8FindsAJCodeDrugLineWithNoNdcTheClaimWouldCarry(): void
    {
        $flags = $this->checks->visit($this->visit(lines: [
            $this->line(1, '99213'),
            $this->line(2, 'J1885', ndc: ''),
            $this->line(3, 'J3420', ndc: 'N400409487501   ML1'),
            $this->line(4, 'J0696', ndc: '00409487501'),
            $this->line(5, 'G0008', ndc: ''),
        ], vaccines: ['141']), $this->today);
        $ndc = array_values(array_filter($flags, fn(Flag $f): bool => $f->kind === Flag::J_CODE_NO_NDC));
        self::assertSame([[2], [4]], array_map(fn(Flag $f): array => $f->lineIds, $ndc),
            'no NDC, and an NDC without the N4 format core writes on the claim; a J-code with one, and a G-code, are fine');
        self::assertSame(['J1885'], $ndc[0]->args);
        self::assertSame([8, Flag::QUALITY], [$ndc[0]->check(), $ndc[0]->direction()]);
        self::assertSame([], $this->kinds($this->visit(lines: [$this->line(1, 'J1885', '', 'CPT4')])),
            'only HCPCS lines: core asks for an NDC on those, and only those');
        self::assertSame([Flag::J_CODE_NO_NDC], $this->kinds($this->visit(lines: [$this->line(1, 'j3490', '', 'HCPCS', ndc: 'N4 ')])),
            'a code typed in lower case, and an NDC field left with only its prefix');
    }

    public function testCheck8RunsOnlyWhileTheSiteHasItOn(): void
    {
        $codes = json_decode((string)file_get_contents(dirname(__DIR__) . '/data/codes.json'), true);
        $rules = fn(?bool $checkNdc): Rules => new Rules(...[
            'serviceTypes' => ['CPT4', 'HCPCS'],
            'justifiedTypes' => ['CPT4', 'HCPCS'],
            'vaccines' => [],
            'administration' => array_map(fn(array $c): array => ['type' => $c['type'], 'code' => $c['code']], $codes['vaccineAdministration']['codes']),
            'visitLevel' => $codes['visitLevel']['ranges'],
            'neverBillable' => [],
            'daysToBill' => 3,
        ] + ($checkNdc === null ? [] : ['checkNdc' => $checkNdc]));
        $visit = $this->visit(lines: [$this->line(1, '99213'), $this->line(2, 'J1885', '', 'HCPCS', ndc: '')]);
        $kinds = fn(?bool $checkNdc): array => array_map(fn(Flag $f): string => $f->kind, (new ChargeChecks($rules($checkNdc)))->visit($visit, $this->today));
        self::assertSame([Flag::J_CODE_NO_NDC], $kinds(true));
        self::assertSame([], $kinds(false), 'turned off, the line is not flagged');
        self::assertSame([Flag::J_CODE_NO_NDC], $kinds(null), 'on unless the site turns it off');
    }

    public function testAFlagKeepsItsKeyAndCarriesItsDirection(): void
    {
        $a = $this->checks->visit($this->visit(lines: [$this->line(1, '99214', '25')]), $this->today)[0];
        $b = $this->checks->visit($this->visit(lines: [$this->line(1, '99214', '25')]), $this->today->modify('+5 days'))[0];
        self::assertSame($a->key(), $b->key());
        self::assertMatchesRegularExpression('/^revcycle:7:[1-9]\\d{17}$/D', $a->key(), "the Staff Worklist's key pattern: a number for the ref");
        $other = $this->checks->visit($this->visit(lines: [$this->line(1, '99215', '25')]), $this->today)[0];
        self::assertNotSame($a->key(), (new \OpenHealthcare\RevenueCycle\Domain\Flag(Flag::NO_DIAGNOSIS, 501, 7, 'line:1'))->key(), 'another kind on the same line is another flag');
        self::assertSame($a->key(), $other->key(), 'the same finding on the same line, whatever the code, is the same flag');
        self::assertSame([6, Flag::OVER], [$a->check(), $a->direction()]);
        self::assertSame([1, Flag::MISSED], [Flag::KINDS[Flag::SEEN_NOT_BILLED][0], Flag::KINDS[Flag::SEEN_NOT_BILLED][1]]);
    }

    public function testTheCodeListShipsCptNumbersOnlyWithOurLabels(): void
    {
        $text = (string)file_get_contents(dirname(__DIR__) . '/data/codes.json');
        $codes = json_decode($text, true);
        self::assertStringContainsString('CPT is a registered trademark of the American Medical Association', $codes['terms']);
        foreach ($codes['vaccineAdministration']['codes'] as $c) {
            self::assertLessThan(70, strlen($c['label']), "{$c['code']}: a short label of ours, not a descriptor");
        }
    }

    /**
     * @param list<Line>                                $lines
     * @param list<string>                              $vaccines
     * @param list<array{code: string, resulted: bool}> $tests
     */
    public function testCheck9FindsAnInsuredClaimWhoseReferringProviderHasNoNpi(): void
    {
        $billed = [$this->line(1, '99213')];
        $none = new Referrer(41, Referrer::PATIENT, 'Ann Lee', '');
        $flags = $this->checks->visit($this->visit(lines: $billed, referrer: $none, insured: true), $this->today);
        self::assertSame([Flag::REFERRER_NO_NPI], array_map(fn(Flag $f): string => $f->kind, $flags));
        self::assertSame(['Ann Lee', Referrer::PATIENT], $flags[0]->args, 'names the referrer and where the claim takes it from');
        self::assertSame('referrer:41', $flags[0]->subject);
        self::assertSame(9, $flags[0]->check());
        self::assertSame([Flag::REFERRER_NO_NPI], $this->kinds($this->visit(lines: $billed, referrer: new Referrer(41, Referrer::VISIT, 'Ann Lee', ' ** '), insured: true)),
            'an NPI that X12 cleaning empties is none');

        self::assertSame([], $this->kinds($this->visit(lines: $billed, referrer: new Referrer(41, Referrer::PATIENT, 'Ann Lee', '1234567893'), insured: true)), 'a referrer with an NPI');
        self::assertSame([], $this->kinds($this->visit(lines: $billed, referrer: $none, insured: false)), 'no insurance in force: no claim goes to a payer');
        self::assertSame([], $this->kinds($this->visit(lines: [], referrer: $none, insured: true, date: '2026-10-19')), 'no service line: nothing to claim');
        self::assertSame([], $this->kinds($this->visit(lines: $billed, referrer: null, insured: true)), 'no referrer on the claim');
    }

    public function testTheReferrerIsChosenAsCoreChoosesIt(): void
    {
        // Box 17 first; on 8.4.0 and later only with qualifier empty or DN (referring).
        self::assertSame(['id' => 5, 'source' => Referrer::BOX_17], Referrer::choose(5, '', true, 6, 7, false));
        self::assertSame(['id' => 5, 'source' => Referrer::BOX_17], Referrer::choose(5, 'DN', true, 6, 7, false));
        self::assertSame(['id' => 6, 'source' => Referrer::VISIT], Referrer::choose(5, 'DK', true, 6, 7, false), 'an ordering provider in box 17 is not the referrer');
        self::assertSame(['id' => 5, 'source' => Referrer::BOX_17], Referrer::choose(5, 'DK', false, 6, 7, false), 'before 8.4.0 any box 17 provider is');
        // Then the visit, then Demographics, unless the rendering provider goes as the Medicare referrer.
        self::assertSame(['id' => 6, 'source' => Referrer::VISIT], Referrer::choose(0, '', true, 6, 7, true));
        self::assertSame(['id' => 7, 'source' => Referrer::PATIENT], Referrer::choose(0, '', true, 0, 7, false));
        self::assertNull(Referrer::choose(0, '', true, 0, 7, true), 'the rendering provider goes instead, and is left alone');
        self::assertNull(Referrer::choose(0, '', true, 0, 0, false));
    }

    private function visit(string $date = '2026-10-10', bool $documented = true, array $lines = [], array $vaccines = [], array $tests = [], int $category = 5,
        ?Referrer $referrer = null, bool $insured = false): Visit
    {
        return new Visit(501, 7, $date, $category, 'normal', 3, 2, $documented, false, $lines, $vaccines, $tests, $referrer, $insured);
    }

    private function line(int $id, string $code, string $modifier = '', ?string $type = null, string $justify = 'ICD10|Z00.00:', string $ndc = ''): Line
    {
        $type ??= preg_match('/^[GJ]/', $code) === 1 ? 'HCPCS' : 'CPT4';
        return new Line($id, $type, $code, $modifier, 1, 100.0, $justify, $ndc);
    }

    /** @return list<string> */
    private function kinds(Visit $v): array
    {
        return array_map(fn(Flag $f): string => $f->kind, $this->checks->visit($v, $this->today));
    }
}
