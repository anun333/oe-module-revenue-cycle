<?php

/**
 * Visits as the charge checks read them, from OpenEMR's own tables (checked against master's schema, 0666c1e915).
 *
 * Documented: a form on the visit other than the visit form itself, or an e-signature on the visit or one of its
 * forms. Claimed: a claims row, or a fee sheet line already billed. A vaccine given here: the record OpenEMR makes
 * for one (information source "new immunization record"), not a history entry, an error or a refusal. A vaccine
 * recorded without choosing a visit (the Immunizations screen's visit select is optional) belongs to the visit the
 * patient had that day, when there was exactly one.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\RevenueCycle\Domain\Line;
use OpenHealthcare\RevenueCycle\Domain\Visit;

final class VisitSource
{
    private const CHUNK = 500;

    /** An immunizations row that records a vaccine given here (the `i` alias). */
    private const GIVEN = "i.information_source = 'new_immunization_record' AND COALESCE(i.added_erroneously, 0) = 0
                    AND COALESCE(i.refusal_reason, '') = '' AND COALESCE(i.completion_status, '') NOT IN ('Not_Administered', 'Refused')
                    AND i.cvx_code <> ''";

    /**
     * SQL that's true while the visit under this form_encounter alias is there. Core's deleter removes the
     * form_encounter row; a visit form deleted from the encounter keeps its row, with forms.deleted = 1 on its
     * newpatient form, and core's own visit lists then hide it (interface/patient_file/history/encounters.php
     * joins newpatient with deleted = 0). A visit with no newpatient row at all still counts.
     */
    public static function live(string $alias): string
    {
        return "(NOT EXISTS (SELECT 1 FROM forms AS np WHERE np.pid = $alias.pid AND np.encounter = $alias.encounter AND np.formdir = 'newpatient' AND np.deleted = 1)
                 OR EXISTS (SELECT 1 FROM forms AS np WHERE np.pid = $alias.pid AND np.encounter = $alias.encounter AND np.formdir = 'newpatient' AND np.deleted = 0))";
    }

    /**
     * Visits dated from $from up to (not including) $to; claimed ones only, unclaimed only, or both (null).
     *
     * @param list<int> $inClinicLabs procedure providers whose tests are run in the clinic
     * @return list<Visit>
     */
    public function visits(string $from, string $to, ?bool $claimed, array $inClinicLabs, ?int $pid = null): array
    {
        $binds = [$from, $to];
        $patient = '';
        if ($pid !== null) {
            $patient = 'AND fe.pid = ?';
            $binds[] = $pid;
        }
        $rows = QueryUtils::fetchRecords(
            "SELECT fe.id, fe.encounter, fe.pid, fe.date, fe.pc_catid, fe.sensitivity, fe.facility_id, fe.provider_id,
                    EXISTS (SELECT 1 FROM claims AS c WHERE c.patient_id = fe.pid AND c.encounter_id = fe.encounter)
                    OR EXISTS (SELECT 1 FROM billing AS b WHERE b.pid = fe.pid AND b.encounter = fe.encounter AND b.activity = 1 AND b.billed = 1) AS claimed,
                    EXISTS (SELECT 1 FROM forms AS f WHERE f.pid = fe.pid AND f.encounter = fe.encounter AND f.deleted = 0 AND f.formdir <> 'newpatient')
                    OR EXISTS (SELECT 1 FROM esign_signatures AS s WHERE s.`table` = 'form_encounter' AND s.tid = fe.id)
                    OR EXISTS (SELECT 1 FROM esign_signatures AS s JOIN forms AS f ON s.`table` = 'forms' AND s.tid = f.id
                               WHERE f.pid = fe.pid AND f.encounter = fe.encounter AND f.deleted = 0) AS documented
               FROM form_encounter AS fe
              WHERE fe.date >= ? AND fe.date < ? $patient AND " . self::live('fe') . "
              ORDER BY fe.date, fe.encounter",
            $binds
        );
        if ($claimed !== null) {
            $rows = array_values(array_filter($rows, fn(array $r): bool => (bool)$r['claimed'] === $claimed));
        }
        if ($rows === []) {
            return [];
        }
        $encounters = array_map(fn(array $r): int => (int)$r['encounter'], $rows);
        $pidOf = [];
        foreach ($rows as $r) {
            $pidOf[(int)$r['encounter']] = (int)$r['pid'];
        }
        $lines = $this->lines($encounters, $pidOf);
        $vaccines = $this->vaccines($encounters, $pidOf);
        foreach ($this->sameDayVaccines($rows, $from, $to) as $e => $codes) {
            $vaccines[$e] = [...($vaccines[$e] ?? []), ...$codes];
        }
        $tests = $inClinicLabs === [] ? [] : $this->tests($encounters, $inClinicLabs, $pidOf);
        $out = [];
        foreach ($rows as $r) {
            $e = (int)$r['encounter'];
            $out[] = new Visit(
                encounter: $e,
                pid: (int)$r['pid'],
                date: substr((string)$r['date'], 0, 10),
                categoryId: (int)$r['pc_catid'],
                sensitivity: (string)($r['sensitivity'] ?? ''),
                facilityId: (int)$r['facility_id'],
                providerId: (int)$r['provider_id'],
                documented: (bool)$r['documented'],
                claimed: (bool)$r['claimed'],
                lines: $lines[$e] ?? [],
                vaccinesGiven: $vaccines[$e] ?? [],
                inClinicTests: $tests[$e] ?? [],
            );
        }
        return $out;
    }

    /**
     * Appointments marked as seen, with no visit for that patient on that day.
     *
     * @param list<string> $seenStatuses apptstat option ids
     * @return list<array{eid: int, pid: int, date: string, facility: int, provider: int}>
     */
    public function seenWithoutVisit(string $from, string $to, array $seenStatuses): array
    {
        if ($seenStatuses === []) {
            return [];
        }
        return array_map(fn(array $r): array => ['eid' => (int)$r['pc_eid'], 'pid' => (int)$r['pc_pid'], 'date' => (string)$r['pc_eventDate'],
            'facility' => (int)$r['pc_facility'], 'provider' => (int)$r['pc_aid']], QueryUtils::fetchRecords(
                'SELECT e.pc_eid, e.pc_pid, e.pc_eventDate, e.pc_facility, e.pc_aid FROM openemr_postcalendar_events AS e
                  WHERE e.pc_eventDate >= ? AND e.pc_eventDate < ? AND e.pc_pid > 0 AND e.pc_apptstatus IN (' . self::marks($seenStatuses) . ')
                    AND NOT EXISTS (SELECT 1 FROM form_encounter AS fe WHERE fe.pid = e.pc_pid AND DATE(fe.date) = e.pc_eventDate)',
                [$from, $to, ...$seenStatuses]
            ));
    }

    /** @return array{service: list<string>, justified: list<string>} core's code types: which carry fees, which need a diagnosis */
    public function codeTypes(): array
    {
        $out = ['service' => [], 'justified' => []];
        foreach (QueryUtils::fetchRecords('SELECT ct_key, ct_fee, ct_just, ct_diag FROM code_types WHERE ct_active = 1') as $r) {
            if ((int)$r['ct_fee'] === 1 && (int)$r['ct_diag'] === 0) {
                $out['service'][] = (string)$r['ct_key'];
                if (trim((string)$r['ct_just']) !== '') {
                    $out['justified'][] = (string)$r['ct_key'];
                }
            }
        }
        return $out;
    }

    /**
     * The statuses that mean the patient was seen: the ones core's list marks arrived or checked out, but not
     * "left without visit", which core also marks as a check-out; plus in exam room and coding done when the list
     * has them, and the status the site's fee sheet offers after a save (Billing > Fee Sheet Save: Ask to Set
     * Appointment Status, OpenEMR 8.5.0's fee_sheet_save_appt_status, #14790), which marks a visit as coded.
     *
     * @return list<string>
     */
    public function seenStatuses(): array
    {
        return self::seen(
            QueryUtils::fetchRecords("SELECT option_id, title, toggle_setting_1, toggle_setting_2 FROM list_options WHERE list_id = 'apptstat' AND activity = 1"),
            OpenEmr::setting('fee_sheet_save_appt_status')
        );
    }

    /**
     * seenStatuses() from the active apptstat options and that setting ('' where unset or before 8.5.0).
     *
     * @param list<array<string, mixed>> $options
     * @return list<string>
     */
    public static function seen(array $options, string $coded): array
    {
        $coded = trim($coded);
        $out = [];
        foreach ($options as $r) {
            $id = (string)($r['option_id'] ?? '');
            $title = (string)($r['title'] ?? '');
            $left = stripos($title, 'w/o visit') !== false || stripos($title, 'without visit') !== false;
            // Core's prompt never sets its status on a canceled (x), canceled < 24h (%) or no-show (?) appointment, so
            // none of those becomes "seen" through the setting.
            $named = in_array($id, ['<', '$'], true) || ($coded !== '' && $id === $coded && !in_array($id, ['x', '%', '?'], true));
            if (!$left && ((int)($r['toggle_setting_1'] ?? 0) === 1 || (int)($r['toggle_setting_2'] ?? 0) === 1 || $named)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @return list<array{ppid: int, name: string}> */
    public function procedureProviders(): array
    {
        return array_map(fn(array $r): array => ['ppid' => (int)$r['ppid'], 'name' => (string)$r['name']],
            QueryUtils::fetchRecords('SELECT ppid, name FROM procedure_providers WHERE active = 1 ORDER BY name'));
    }

    /** @return list<array{id: int, name: string}> */
    public function categories(): array
    {
        return array_map(fn(array $r): array => ['id' => (int)$r['pc_catid'], 'name' => (string)$r['pc_catname']],
            QueryUtils::fetchRecords('SELECT pc_catid, pc_catname FROM openemr_postcalendar_categories WHERE pc_cattype = 0 ORDER BY pc_catname'));
    }

    /**
     * The clinic's fee for a code to this patient, from its own fee schedule, or null when it has none. The price is
     * at the patient's own price level (patient_data.pricelevel), as the fee sheet charges a new line on 8.4.1
     * (FeeSheet::addServiceLineItem()) and as core's lookup does on master (src/Billing/FeeSheetChargeLookup.php,
     * which joins patient_data the same way); 0.1.3 and earlier priced every patient at "standard". The code's row
     * is the one that lookup picks since #14719 (ORDER BY c.active DESC, c.modifier, c.id): an active row first, then
     * the one without a modifier, then the oldest. When that row has no price at the patient's level, there is none:
     * the fee sheet would charge none either. A percentage level (Billing > enable_percent_pricing) isn't applied,
     * as core's lookup doesn't apply it.
     */
    public function price(string $codeType, string $code, int $pid): ?float
    {
        $row = QueryUtils::fetchRecords(
            "SELECT p.pr_price FROM codes AS c JOIN code_types AS ct ON ct.ct_id = c.code_type
               LEFT JOIN patient_data AS pd ON pd.pid = ?
               LEFT JOIN prices AS p ON p.pr_id = c.id AND p.pr_selector = '' AND p.pr_level = pd.pricelevel
              WHERE ct.ct_key = ? AND c.code = ?
              ORDER BY c.active DESC, c.modifier, c.id LIMIT 1",
            [$pid, $codeType, $code]
        )[0] ?? null;
        return $row === null || !is_numeric($row['pr_price'] ?? null) ? null : (float)$row['pr_price'];
    }

    /**
     * @param list<int>        $encounters
     * @param array<int, int>  $pidOf      each visit's patient
     * @return array<int, list<Line>>
     */
    private function lines(array $encounters, array $pidOf): array
    {
        $out = [];
        foreach (array_chunk($encounters, self::CHUNK) as $chunk) {
            $pids = self::patients($chunk, $pidOf);
            foreach (QueryUtils::fetchRecords(
                'SELECT id, encounter, code_type, code, modifier, units, fee, justify, ndc_info FROM billing
                  WHERE activity = 1 AND pid IN (' . self::marks($pids) . ') AND encounter IN (' . self::marks($chunk) . ') ORDER BY id',
                [...$pids, ...$chunk]
            ) as $r) {
                $out[(int)$r['encounter']][] = new Line((int)$r['id'], (string)$r['code_type'], trim((string)$r['code']), trim((string)($r['modifier'] ?? '')),
                    max(1, (int)$r['units']), (float)$r['fee'], (string)($r['justify'] ?? ''), trim((string)($r['ndc_info'] ?? '')));
            }
        }
        return $out;
    }

    /**
     * @param list<int> $encounters
     * @param array<int, int> $pidOf each visit's patient
     * @return array<int, list<string>>
     */
    private function vaccines(array $encounters, array $pidOf): array
    {
        $out = [];
        foreach (array_chunk($encounters, self::CHUNK) as $chunk) {
            $pids = self::patients($chunk, $pidOf);
            foreach (QueryUtils::fetchRecords(
                'SELECT i.encounter_id, i.cvx_code FROM immunizations AS i
                  WHERE ' . self::GIVEN . ' AND i.patient_id IN (' . self::marks($pids) . ') AND i.encounter_id IN (' . self::marks($chunk) . ')',
                [...$pids, ...$chunk]
            ) as $r) {
                $out[(int)$r['encounter_id']][] = trim((string)$r['cvx_code']);
            }
        }
        return $out;
    }

    /**
     * Vaccines given in the window with no visit chosen, each placed on the patient's visit that day when there was
     * exactly one (counting every visit that day, not only the ones listed). Our UDS module joins such records to a
     * same-day visit too; here a day with two visits places none, since a flag on the wrong visit is worse than none.
     *
     * @param list<array<string, mixed>> $rows the listed visits
     * @return array<int, list<string>> encounter => CVX codes
     */
    private function sameDayVaccines(array $rows, string $from, string $to): array
    {
        $pids = array_values(array_unique(array_map(fn(array $r): int => (int)$r['pid'], $rows)));
        $unlinked = [];
        foreach (array_chunk($pids, self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords(
                'SELECT i.patient_id, DATE(i.administered_date) AS day, i.cvx_code FROM immunizations AS i
                  WHERE ' . self::GIVEN . ' AND COALESCE(i.encounter_id, 0) = 0
                    AND i.administered_date >= ? AND i.administered_date < ? AND i.patient_id IN (' . self::marks($chunk) . ')',
                [$from, $to, ...$chunk]
            ) as $r) {
                $unlinked[] = ['pid' => (int)$r['patient_id'], 'day' => (string)$r['day'], 'cvx' => trim((string)$r['cvx_code'])];
            }
        }
        if ($unlinked === []) {
            return [];
        }
        $days = [];
        foreach (array_chunk(array_values(array_unique(array_column($unlinked, 'pid'))), self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords(
                'SELECT fe.pid, DATE(fe.date) AS day, COUNT(*) AS visits, MIN(fe.encounter) AS encounter FROM form_encounter AS fe
                  WHERE fe.date >= ? AND fe.date < ? AND fe.pid IN (' . self::marks($chunk) . ') AND ' . self::live('fe') . '
                  GROUP BY fe.pid, DATE(fe.date)',
                [$from, $to, ...$chunk]
            ) as $r) {
                $days[] = ['pid' => (int)$r['pid'], 'day' => (string)$r['day'], 'visits' => (int)$r['visits'], 'encounter' => (int)$r['encounter']];
            }
        }
        return self::sameDay($unlinked, $days, array_map(fn(array $r): int => (int)$r['encounter'], $rows));
    }

    /**
     * sameDayVaccines()'s rule on rows already read: a vaccine goes to the patient's one visit that day, if that visit
     * is listed.
     *
     * @param list<array{pid: int, day: string, cvx: string}>                    $unlinked
     * @param list<array{pid: int, day: string, visits: int, encounter: int}>   $days
     * @param list<int>                                                          $listed
     * @return array<int, list<string>>
     */
    public static function sameDay(array $unlinked, array $days, array $listed): array
    {
        $only = [];
        foreach ($days as $d) {
            if ($d['visits'] === 1) {
                $only[$d['pid'] . '|' . $d['day']] = $d['encounter'];
            }
        }
        $out = [];
        foreach ($unlinked as $v) {
            $e = $only[$v['pid'] . '|' . $v['day']] ?? null;
            if ($e !== null && in_array($e, $listed, true) && $v['cvx'] !== '') {
                $out[$e][] = $v['cvx'];
            }
        }
        return $out;
    }

    /**
     * Orders from the in-clinic providers, each test by its standard code (procedure_type.standard_code, such as
     * CPT4:87880), resulted when the order line has a report.
     *
     * @param list<int> $encounters
     * @param list<int> $labs
     * @param array<int, int> $pidOf each visit's patient
     * @return array<int, list<array{code: string, resulted: bool}>>
     */
    private function tests(array $encounters, array $labs, array $pidOf): array
    {
        $out = [];
        foreach (array_chunk($encounters, self::CHUNK) as $chunk) {
            $pids = self::patients($chunk, $pidOf);
            foreach (QueryUtils::fetchRecords(
                'SELECT po.encounter_id, COALESCE(NULLIF(pt.standard_code, \'\'), poc.procedure_code) AS code,
                        EXISTS (SELECT 1 FROM procedure_report AS pr WHERE pr.procedure_order_id = po.procedure_order_id AND pr.procedure_order_seq = poc.procedure_order_seq) AS resulted
                   FROM procedure_order AS po
                   JOIN procedure_order_code AS poc ON poc.procedure_order_id = po.procedure_order_id
                   LEFT JOIN procedure_type AS pt ON pt.lab_id = po.lab_id AND pt.procedure_code = poc.procedure_code AND pt.procedure_type = \'ord\'
                  WHERE po.activity = 1 AND po.lab_id IN (' . self::marks($labs) . ') AND po.patient_id IN (' . self::marks($pids) . ')
                    AND po.encounter_id IN (' . self::marks($chunk) . ')',
                [...$labs, ...$pids, ...$chunk]
            ) as $r) {
                $code = (string)preg_replace('/^[A-Z0-9-]+:/', '', trim((string)$r['code']));
                $out[(int)$r['encounter_id']][] = ['code' => $code, 'resulted' => (bool)$r['resulted']];
            }
        }
        return $out;
    }

    /**
     * The patients of a chunk of visits. Core indexes billing, immunizations and procedure_order by patient, not by
     * visit: naming the patients alongside the visits lets each lookup use that index instead of reading the whole table
     * for every chunk.
     *
     * @param list<int> $chunk
     * @param array<int, int> $pidOf
     * @return list<int>
     */
    private static function patients(array $chunk, array $pidOf): array
    {
        return array_values(array_unique(array_map(fn(int $e): int => $pidOf[$e] ?? 0, $chunk)));
    }

    /** @param list<int|string> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
