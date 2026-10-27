<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\RevenueCycle\Repository\FlagLog;
use OpenHealthcare\RevenueCycle\Repository\VisitSource;
use PHPUnit\Framework\TestCase;

/**
 * What the visit and flag queries ask for: which appointment statuses mean seen, which code row prices a charge,
 * and that a deleted visit's flags drop out of the look-back. The rows themselves come from the database, so the
 * end-to-end check (dev/revenue_cycle_check.py) runs these against OpenEMR.
 */
final class VisitSourceTest extends TestCase
{
    /** OpenEMR's stock apptstat options, as list_options holds them (toggle_setting_1 arrived, _2 checked out). */
    private const STOCK = [
        ['option_id' => '-', 'title' => 'None', 'toggle_setting_1' => 0, 'toggle_setting_2' => 0],
        ['option_id' => '@', 'title' => 'Arrived', 'toggle_setting_1' => 1, 'toggle_setting_2' => 0],
        ['option_id' => '<', 'title' => 'In exam room', 'toggle_setting_1' => 0, 'toggle_setting_2' => 0],
        ['option_id' => '>', 'title' => 'Checked out', 'toggle_setting_1' => 0, 'toggle_setting_2' => 1],
        ['option_id' => '$', 'title' => 'Coding done', 'toggle_setting_1' => 0, 'toggle_setting_2' => 0],
        ['option_id' => '!', 'title' => 'Left w/o visit', 'toggle_setting_1' => 0, 'toggle_setting_2' => 1],
        ['option_id' => 'x', 'title' => 'Canceled', 'toggle_setting_1' => 0, 'toggle_setting_2' => 0],
        ['option_id' => '?', 'title' => 'No show', 'toggle_setting_1' => 0, 'toggle_setting_2' => 0],
        ['option_id' => 'C', 'title' => 'Coded by billing', 'toggle_setting_1' => 0, 'toggle_setting_2' => 0],
    ];

    protected function tearDown(): void
    {
        QueryUtils::$results = [];
        QueryUtils::$calls = [];
        unset($GLOBALS['openemr_version'], $GLOBALS['MedicareReferrerIsRenderer']);
    }

    public function testSeenStatusesAreTodaysWhenTheFeeSheetSettingIsUnset(): void
    {
        $this->assertSame(['@', '<', '>', '$'], VisitSource::seen(self::STOCK, ''));
    }

    public function testTheFeeSheetsAfterSaveStatusCountsAsSeen(): void
    {
        $this->assertSame(['@', '<', '>', '$', 'C'], VisitSource::seen(self::STOCK, 'C'));
        $this->assertSame(['@', '<', '>', '$', 'C'], VisitSource::seen(self::STOCK, ' C '), 'as typed into the setting');
    }

    public function testACanceledNoShowUnknownOrLeftStatusNeverBecomesSeen(): void
    {
        foreach (['x', '?', 'Z', '!'] as $coded) {
            $this->assertSame(['@', '<', '>', '$'], VisitSource::seen(self::STOCK, $coded), $coded);
        }
    }

    public function testAChargeIsPricedFromTheRowCoresFeeSheetLookupPicks(): void
    {
        QueryUtils::$results = [[['pr_price' => '44.00']]];
        $this->assertSame(44.0, (new VisitSource())->price('CPT4', '92015', 7));
        $sql = QueryUtils::$calls[0]['sql'];
        $this->assertStringContainsString('ORDER BY c.active DESC, c.modifier, c.id LIMIT 1', $sql);
        $this->assertStringContainsString('LEFT JOIN prices', $sql, 'the row is chosen first, then its price');
        $this->assertStringNotContainsString("c.modifier = ''", $sql, 'a code whose only row has a modifier (JZ) still has a price');
    }

    public function testAChargeIsPricedAtThePatientsOwnPriceLevel(): void
    {
        QueryUtils::$results = [[['pr_price' => '30.00']]];
        $this->assertSame(30.0, (new VisitSource())->price('CPT4', '92015', 7));
        $sql = QueryUtils::$calls[0]['sql'];
        $this->assertStringContainsString('LEFT JOIN patient_data AS pd ON pd.pid = ?', $sql, "the patient's row, as core's lookup joins it");
        $this->assertStringContainsString('p.pr_level = pd.pricelevel', $sql, "the patient's level, not a fixed one");
        $this->assertStringNotContainsString("'standard'", $sql);
        $this->assertSame([7, 'CPT4', '92015'], QueryUtils::$calls[0]['binds']);
    }

    public function testThatRowWithoutAPriceIsNoPrice(): void
    {
        QueryUtils::$results = [[['pr_price' => null]]];
        $this->assertNull((new VisitSource())->price('CPT4', '92015', 7), 'no price at the patient\'s level');
        $this->assertNull((new VisitSource())->price('CPT4', '00000', 7), 'no row at all');
    }

    public function testTheLookBackCountsOnlyFlagsWhoseVisitIsThere(): void
    {
        QueryUtils::$results = [[['n' => 2]], [['check_no' => 1, 'direction' => 'missed', 'found' => 3, 'accepted' => 1, 'dismissed' => 1]]];
        $counts = (new FlagLog())->counts('2026-01-01', '2027-01-01');
        $this->assertSame([['check_no' => 1, 'direction' => 'missed', 'found' => 3, 'accepted' => 1, 'dismissed' => 1, 'open' => 1]], $counts);
        $sql = QueryUtils::$calls[1]['sql'];
        $this->assertStringContainsString('f.encounter = 0 OR EXISTS (SELECT 1 FROM form_encounter AS fe WHERE fe.pid = f.pid AND fe.encounter = f.encounter', $sql);
        $this->assertStringContainsString("np.formdir = 'newpatient' AND np.deleted = 1", $sql, 'a visit deleted from its encounter, as core marks it');
        $this->assertStringContainsString('FROM staff_worklist_state', $sql);
    }

    public function testWithoutTheWorklistsColumnsNothingIsDismissed(): void
    {
        QueryUtils::$results = [[['n' => 1]], []];
        (new FlagLog())->counts('2026-01-01', '2027-01-01');
        $this->assertStringContainsString("column_name IN ('item_key', 'status')", QueryUtils::$calls[0]['sql']);
        $this->assertStringNotContainsString('staff_worklist_state', QueryUtils::$calls[1]['sql']);
    }

    public function testAVaccineWithNoVisitChosenBelongsToThatDaysOnlyVisit(): void
    {
        $unlinked = [['pid' => 7, 'day' => '2026-10-01', 'cvx' => '140']];
        $one = [['pid' => 7, 'day' => '2026-10-01', 'visits' => 1, 'encounter' => 501]];
        $this->assertSame([501 => ['140']], VisitSource::sameDay($unlinked, $one, [501]));

        $two = [['pid' => 7, 'day' => '2026-10-01', 'visits' => 2, 'encounter' => 501]];
        $this->assertSame([], VisitSource::sameDay($unlinked, $two, [501]), 'two visits that day: no guess');
        $this->assertSame([], VisitSource::sameDay($unlinked, $one, [502]), 'that visit is not in the list being checked');
        $other = [['pid' => 7, 'day' => '2026-10-02', 'visits' => 1, 'encounter' => 501]];
        $this->assertSame([], VisitSource::sameDay($unlinked, $other, [501]), 'a visit on another day');
        $someoneElse = [['pid' => 8, 'day' => '2026-10-01', 'visits' => 1, 'encounter' => 501]];
        $this->assertSame([], VisitSource::sameDay($unlinked, $someoneElse, [501]), "another patient's visit");
    }

    public function testTheVisitListCarriesAVaccineRecordedWithoutAVisit(): void
    {
        QueryUtils::$results = [
            [['id' => 1, 'encounter' => 501, 'pid' => 7, 'date' => '2026-10-01 09:00:00', 'pc_catid' => 5, 'sensitivity' => '',
              'facility_id' => 3, 'provider_id' => 4, 'claimed' => 1, 'documented' => 1]],
            [],
            [],
            [['patient_id' => 7, 'day' => '2026-10-01', 'cvx_code' => ' 140 ']],
            [['pid' => 7, 'day' => '2026-10-01', 'visits' => 1, 'encounter' => 501]],
        ];
        $visits = (new VisitSource())->visits('2026-10-01', '2026-10-02', null, []);
        $this->assertSame(['140'], $visits[0]->vaccinesGiven);
        $this->assertStringContainsString('COALESCE(i.encounter_id, 0) = 0', QueryUtils::$calls[3]['sql']);
        $this->assertStringContainsString("i.information_source = 'new_immunization_record'", QueryUtils::$calls[3]['sql'], 'given here, as for a linked one');
        $this->assertSame(['2026-10-01', '2026-10-02', 7], QueryUtils::$calls[3]['binds']);
        $this->assertStringContainsString('GROUP BY fe.pid, DATE(fe.date)', QueryUtils::$calls[4]['sql']);
        $this->assertStringContainsString("np.formdir = 'newpatient' AND np.deleted = 1", QueryUtils::$calls[4]['sql'], 'only visits still there are counted');
    }

    public function testWithNoUnlinkedVaccinesTheDaysAreNotRead(): void
    {
        QueryUtils::$results = [
            [['id' => 1, 'encounter' => 501, 'pid' => 7, 'date' => '2026-10-01 09:00:00', 'pc_catid' => 5, 'sensitivity' => '',
              'facility_id' => 3, 'provider_id' => 4, 'claimed' => 1, 'documented' => 1]],
            [],
            [['encounter_id' => 501, 'cvx_code' => '140']],
            [],
        ];
        $visits = (new VisitSource())->visits('2026-10-01', '2026-10-02', null, []);
        $this->assertSame(['140'], $visits[0]->vaccinesGiven, 'a linked vaccine, as before');
        $this->assertCount(4, QueryUtils::$calls);
    }

    public function testEachLookupIsReadThroughThePatientIndex(): void
    {
        QueryUtils::$results = [
            [['id' => 1, 'encounter' => 501, 'pid' => 7, 'date' => '2026-10-01 09:00:00', 'pc_catid' => 5, 'sensitivity' => '',
              'facility_id' => 3, 'provider_id' => 4, 'claimed' => 1, 'documented' => 1],
             ['id' => 2, 'encounter' => 502, 'pid' => 7, 'date' => '2026-10-01 11:00:00', 'pc_catid' => 5, 'sensitivity' => '',
              'facility_id' => 3, 'provider_id' => 4, 'claimed' => 1, 'documented' => 1],
             ['id' => 3, 'encounter' => 503, 'pid' => 9, 'date' => '2026-10-01 12:00:00', 'pc_catid' => 5, 'sensitivity' => '',
              'facility_id' => 3, 'provider_id' => 4, 'claimed' => 1, 'documented' => 1]],
            [['id' => 10, 'encounter' => 503, 'code_type' => 'CPT4', 'code' => '99213', 'modifier' => '', 'units' => 1, 'fee' => 90, 'justify' => 'ICD10|E11.9:', 'ndc_info' => '']],
            [['encounter_id' => 502, 'cvx_code' => '140']],
            [],
            [['encounter_id' => 501, 'code' => '87880', 'resulted' => 1]],
        ];
        $visits = (new VisitSource())->visits('2026-10-01', '2026-10-02', null, [900]);
        // Core indexes these tables by patient and not by visit: the patients come first, each once.
        $lines = QueryUtils::$calls[1];
        $this->assertStringContainsString('FROM billing', $lines['sql']);
        $this->assertStringContainsString('pid IN (?, ?) AND encounter IN (?, ?, ?)', $lines['sql']);
        $this->assertSame([7, 9, 501, 502, 503], $lines['binds']);
        $vaccines = QueryUtils::$calls[2];
        $this->assertStringContainsString('i.patient_id IN (?, ?) AND i.encounter_id IN (?, ?, ?)', $vaccines['sql']);
        $this->assertSame([7, 9, 501, 502, 503], $vaccines['binds']);
        $tests = QueryUtils::$calls[4];
        $this->assertStringContainsString('FROM procedure_order', $tests['sql']);
        $this->assertMatchesRegularExpression('/po\.patient_id IN \(\?, \?\)\s+AND po\.encounter_id IN \(\?, \?, \?\)/', $tests['sql']);
        $this->assertSame([900, 7, 9, 501, 502, 503], $tests['binds']);
        $this->assertSame([], $visits[0]->lines);
        $this->assertSame('99213', $visits[2]->lines[0]->code, 'the line still lands on its own visit');
        $this->assertSame(['140'], $visits[1]->vaccinesGiven, 'and the vaccine on its own');
        $this->assertSame([['code' => '87880', 'resulted' => true]], $visits[0]->inClinicTests, 'and the test on its own');
    }

    public function testDeletedVisitsAreNotScanned(): void
    {
        QueryUtils::$results = [[]];
        (new VisitSource())->visits('2026-10-01', '2026-10-02', null, []);
        $this->assertStringContainsString("np.formdir = 'newpatient' AND np.deleted = 1", QueryUtils::$calls[0]['sql']);
    }
    public function testThePrimaryInsuranceInForceIsReadAsCoreReadsIt(): void
    {
        $rows = [ // latest date first
            ['provider' => '', 'date' => '2026-09-01', 'date_end' => null],
            ['provider' => '12', 'date' => '2026-01-01', 'date_end' => null],
            ['provider' => '11', 'date' => '2025-01-01', 'date_end' => '2025-12-31'],
        ];
        self::assertSame('12', VisitSource::primaryInForce($rows, '2026-05-01'));
        self::assertNull(VisitSource::primaryInForce($rows, '2026-10-01'), 'a row with no company means no insurance from its date');
        self::assertSame('11', VisitSource::primaryInForce($rows, '2025-06-01'));
        self::assertNull(VisitSource::primaryInForce($rows, '2024-06-01'), 'before any row');
        self::assertSame('9', VisitSource::primaryInForce([['provider' => '9', 'date' => null, 'date_end' => null]], '2026-05-01'), 'an undated row');
        self::assertNull(VisitSource::primaryInForce([['provider' => '0', 'date' => null, 'date_end' => null]], '2026-05-01'));
    }

    public function testTheProviderNumberTypeTakesThePayersRowFirst(): void
    {
        $rows = [['insurance_company_id' => null, 'provider_number_type' => '0B'], ['insurance_company_id' => 12, 'provider_number_type' => '1C']];
        self::assertSame('1C', VisitSource::providerNumberType($rows, '12'));
        self::assertSame('0B', VisitSource::providerNumberType($rows, '13'), 'the row with no company otherwise');
        self::assertSame('', VisitSource::providerNumberType([], '12'));
    }
    /**
     * A visit with a line gets the referrer its claim would carry: box 17's ordering provider (DK) is passed over on
     * 8.4.0 and later, so the Demographics referrer is taken; before 8.4.0 box 17's provider is, whatever its qualifier.
     *
     * @return list<list<array<string, mixed>>>
     */
    private static function claimRows(): array
    {
        return [
            [['id' => 1, 'encounter' => 501, 'pid' => 7, 'date' => '2026-10-01 09:00:00', 'pc_catid' => 5, 'sensitivity' => '',
              'facility_id' => 3, 'provider_id' => 4, 'referring_provider_id' => 0, 'claimed' => 0, 'documented' => 1]],
            [['id' => 9, 'encounter' => 501, 'code_type' => 'CPT4', 'code' => '99213', 'modifier' => '', 'units' => 1, 'fee' => 100, 'justify' => '', 'ndc_info' => '']],
            [],
            [],
            [['pid' => 7, 'ref_providerID' => 41]],
            [['encounter' => 501, 'provider_id' => 5, 'provider_qualifier_code' => 'DK']],
            [['pid' => 7, 'provider' => '12', 'date' => '2026-01-01', 'date_end' => null]],
        ];
    }

    public function testAVisitWithALineGetsItsClaimsReferrerAndPayer(): void
    {
        $GLOBALS['openemr_version'] = '8.4.1';
        QueryUtils::$results = [...self::claimRows(), [['id' => 41, 'fname' => 'Ann', 'lname' => 'Lee', 'npi' => '']]];
        $v = (new VisitSource())->visits('2026-10-01', '2026-10-02', null, [])[0];
        $this->assertTrue($v->insured);
        $this->assertNotNull($v->referrer);
        $this->assertSame([41, 'patient', 'Ann Lee'], [$v->referrer->userId, $v->referrer->source, $v->referrer->name]);
        $this->assertTrue($v->referrer->npiMissing());
        $this->assertSame([41], end(QueryUtils::$calls)['binds'], 'the users row of the chosen referrer');

        QueryUtils::$calls = [];
        $GLOBALS['openemr_version'] = '8.3.0';
        QueryUtils::$results = [...self::claimRows(), [['id' => 5, 'fname' => 'Bo', 'lname' => 'Ray', 'npi' => '1234567893']]];
        $v = (new VisitSource())->visits('2026-10-01', '2026-10-02', null, [])[0];
        $this->assertSame([5, 'box17'], [$v->referrer?->userId, $v->referrer?->source], 'before 8.4.0 box 17 wins');
        $this->assertFalse($v->referrer?->npiMissing());
    }

    public function testTheRenderingProviderAsMedicareReferrerLeavesTheReferrerOut(): void
    {
        $GLOBALS['openemr_version'] = '8.4.1';
        $GLOBALS['MedicareReferrerIsRenderer'] = '1';
        $rows = self::claimRows();
        $rows[5] = []; // no box 17
        QueryUtils::$results = [...$rows, [['provider_id' => 4, 'insurance_company_id' => 12, 'provider_number_type' => '1C']]];
        $v = (new VisitSource())->visits('2026-10-01', '2026-10-02', null, [])[0];
        $this->assertTrue($v->insured);
        $this->assertNull($v->referrer, 'the rendering provider is sent instead');
    }
}
