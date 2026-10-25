<?php

/**
 * Visits as the charge checks read them, from OpenEMR's own tables (checked against master's schema, 0666c1e915).
 *
 * Documented: a form on the visit other than the visit form itself, or an e-signature on the visit or one of its
 * forms. Claimed: a claims row, or a fee sheet line already billed. A vaccine given here: the record OpenEMR makes
 * for one (information source "new immunization record"), not a history entry, an error or a refusal.
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
use OpenHealthcare\RevenueCycle\Domain\Line;
use OpenHealthcare\RevenueCycle\Domain\Visit;

final class VisitSource
{
    private const CHUNK = 500;

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
              WHERE fe.date >= ? AND fe.date < ? $patient
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
        $lines = $this->lines($encounters);
        $vaccines = $this->vaccines($encounters);
        $tests = $inClinicLabs === [] ? [] : $this->tests($encounters, $inClinicLabs);
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
     * "left without visit", which core also marks as a check-out; plus in exam room and coding done when the list has them.
     *
     * @return list<string>
     */
    public function seenStatuses(): array
    {
        $out = [];
        foreach (QueryUtils::fetchRecords("SELECT option_id, title, toggle_setting_1, toggle_setting_2 FROM list_options WHERE list_id = 'apptstat' AND activity = 1") as $r) {
            $left = stripos((string)$r['title'], 'w/o visit') !== false || stripos((string)$r['title'], 'without visit') !== false;
            if (!$left && ((int)$r['toggle_setting_1'] === 1 || (int)$r['toggle_setting_2'] === 1 || in_array((string)$r['option_id'], ['<', '$'], true))) {
                $out[] = (string)$r['option_id'];
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
     * The clinic's fee for a code at a price level, from its own fee schedule, or null when it has none.
     */
    public function price(string $codeType, string $code, string $level): ?float
    {
        $row = QueryUtils::fetchRecords(
            "SELECT p.pr_price FROM codes AS c JOIN code_types AS ct ON ct.ct_id = c.code_type
               JOIN prices AS p ON p.pr_id = c.id AND p.pr_selector = '' AND p.pr_level = ?
              WHERE ct.ct_key = ? AND c.code = ? AND c.modifier = '' LIMIT 1",
            [$level, $codeType, $code]
        )[0] ?? null;
        return $row === null ? null : (float)$row['pr_price'];
    }

    /**
     * @param list<int> $encounters
     * @return array<int, list<Line>>
     */
    private function lines(array $encounters): array
    {
        $out = [];
        foreach (array_chunk($encounters, self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords(
                'SELECT id, encounter, code_type, code, modifier, units, fee, justify FROM billing
                  WHERE activity = 1 AND encounter IN (' . self::marks($chunk) . ') ORDER BY id',
                $chunk
            ) as $r) {
                $out[(int)$r['encounter']][] = new Line((int)$r['id'], (string)$r['code_type'], trim((string)$r['code']), trim((string)($r['modifier'] ?? '')),
                    max(1, (int)$r['units']), (float)$r['fee'], (string)($r['justify'] ?? ''));
            }
        }
        return $out;
    }

    /**
     * @param list<int> $encounters
     * @return array<int, list<string>>
     */
    private function vaccines(array $encounters): array
    {
        $out = [];
        foreach (array_chunk($encounters, self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords(
                "SELECT encounter_id, cvx_code FROM immunizations
                  WHERE information_source = 'new_immunization_record' AND COALESCE(added_erroneously, 0) = 0
                    AND COALESCE(refusal_reason, '') = '' AND COALESCE(completion_status, '') NOT IN ('Not_Administered', 'Refused')
                    AND cvx_code <> '' AND encounter_id IN (" . self::marks($chunk) . ')',
                $chunk
            ) as $r) {
                $out[(int)$r['encounter_id']][] = trim((string)$r['cvx_code']);
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
     * @return array<int, list<array{code: string, resulted: bool}>>
     */
    private function tests(array $encounters, array $labs): array
    {
        $out = [];
        foreach (array_chunk($encounters, self::CHUNK) as $chunk) {
            foreach (QueryUtils::fetchRecords(
                'SELECT po.encounter_id, COALESCE(NULLIF(pt.standard_code, \'\'), poc.procedure_code) AS code,
                        EXISTS (SELECT 1 FROM procedure_report AS pr WHERE pr.procedure_order_id = po.procedure_order_id AND pr.procedure_order_seq = poc.procedure_order_seq) AS resulted
                   FROM procedure_order AS po
                   JOIN procedure_order_code AS poc ON poc.procedure_order_id = po.procedure_order_id
                   LEFT JOIN procedure_type AS pt ON pt.lab_id = po.lab_id AND pt.procedure_code = poc.procedure_code AND pt.procedure_type = \'ord\'
                  WHERE po.activity = 1 AND po.lab_id IN (' . self::marks($labs) . ') AND po.encounter_id IN (' . self::marks($chunk) . ')',
                [...$labs, ...$chunk]
            ) as $r) {
                $code = (string)preg_replace('/^[A-Z0-9-]+:/', '', trim((string)$r['code']));
                $out[(int)$r['encounter_id']][] = ['code' => $code, 'resulted' => (bool)$r['resulted']];
            }
        }
        return $out;
    }

    /** @param list<int|string> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
