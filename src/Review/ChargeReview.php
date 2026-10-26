<?php

/**
 * The charge review: the checks with this site's settings and code types, over visits whose claim hasn't gone out
 * (the queue) or over billed ones (the look-back).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Review;

use OpenHealthcare\RevenueCycle\Domain\ChargeChecks;
use OpenHealthcare\RevenueCycle\Domain\Flag;
use OpenHealthcare\RevenueCycle\Domain\Rules;
use OpenHealthcare\RevenueCycle\Domain\Visit;
use OpenHealthcare\RevenueCycle\Repository\FlagLog;
use OpenHealthcare\RevenueCycle\Repository\Settings;
use OpenHealthcare\RevenueCycle\Repository\VisitSource;

final class ChargeReview
{
    public function __construct(
        private readonly Settings $settings = new Settings(),
        private readonly VisitSource $visits = new VisitSource(),
        private readonly FlagLog $log = new FlagLog(),
    ) {
    }

    public function rules(): Rules
    {
        $codes = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/codes.json'), true, flags: JSON_THROW_ON_ERROR);
        $types = $this->visits->codeTypes();
        return new Rules(
            serviceTypes: $types['service'],
            justifiedTypes: $types['justified'],
            vaccines: $this->settings->vaccines(),
            administration: array_map(fn(array $c): array => ['type' => (string)$c['type'], 'code' => (string)$c['code']], $codes['vaccineAdministration']['codes']),
            visitLevel: array_map(fn(array $r): array => ['type' => (string)$r['type'], 'from' => (string)$r['from'], 'to' => (string)$r['to']], $codes['visitLevel']['ranges']),
            neverBillable: $this->settings->neverBillable(),
            daysToBill: $this->settings->daysToBill(),
            checkNdc: $this->settings->checkNdc(),
        );
    }

    /**
     * The queue: flags on visits since the start date whose claim hasn't gone out, and appointments seen with no
     * visit. A full run (no patient) also records every flag found and clears the ones no longer found.
     *
     * @return list<array{flag: Flag, visit: ?Visit, date: string}>
     */
    public function queue(\DateTimeImmutable $today, ?int $pid = null): array
    {
        $from = $this->settings->startDate($today);
        $to = $today->modify('+1 day')->format('Y-m-d');
        $checks = new ChargeChecks($this->rules());
        $all = $this->visits->visits($from, $to, null, $this->settings->inClinicLabs(), $pid);
        $found = [];
        $queue = [];
        foreach ($all as $visit) {
            foreach ($checks->visit($visit, $today) as $flag) {
                $found[] = $flag;
                if (!$visit->claimed) {
                    $queue[] = ['flag' => $flag, 'visit' => $visit, 'date' => $visit->date];
                }
            }
        }
        $cutoff = $today->modify('-' . $this->settings->daysToBill() . ' days')->format('Y-m-d');
        foreach ($this->visits->seenWithoutVisit($from, $cutoff, $this->visits->seenStatuses()) as $a) {
            if ($pid === null || $a['pid'] === $pid) {
                $flag = new Flag(Flag::SEEN_NO_VISIT, 0, $a['pid'], "appt:{$a['eid']}", [$a['date']]);
                $found[] = $flag;
                $queue[] = ['flag' => $flag, 'visit' => null, 'date' => $a['date']];
            }
        }
        if ($pid === null) {
            $this->log->record($found, array_map(fn(Visit $v): int => $v->encounter, $all), new \DateTimeImmutable());
        }
        return $queue;
    }

    /**
     * The look-back: flags on billed visits in the period, both directions, each with the charge it concerns from
     * the clinic's own fee schedule at the patient's own price level (a charge, not an expected payment) and, for a missed charge, the days left in
     * the year a Medicare claim can still be corrected.
     *
     * @return list<array{flag: Flag, visit: Visit, amount: ?float, daysLeft: ?int}>
     */
    public function lookBack(string $from, string $to, \DateTimeImmutable $today): array
    {
        $checks = new ChargeChecks($this->rules());
        $rules = $this->rules();
        $out = [];
        foreach ($this->visits->visits($from, $to, true, $this->settings->inClinicLabs()) as $visit) {
            foreach ($checks->visit($visit, $today) as $flag) {
                $amount = null;
                if ($flag->direction() === Flag::OVER) {
                    $amount = array_sum(array_map(fn($l): float => $l->fee, array_filter($visit->lines, fn($l): bool => in_array($l->id, $flag->lineIds, true))));
                } elseif ($flag->kind === Flag::VACCINE_NO_PRODUCT) {
                    $product = $rules->vaccines[$flag->args[0]] ?? null;
                    $amount = $product === null ? null : $this->visits->price($product['type'], $product['code'], $visit->pid);
                } elseif ($flag->kind === Flag::TEST_NOT_BILLED) {
                    $amount = $this->visits->price('CPT4', $flag->args[0], $visit->pid) ?? $this->visits->price('HCPCS', $flag->args[0], $visit->pid);
                }
                $age = (int)$today->diff(new \DateTimeImmutable($visit->date))->format('%a');
                $out[] = ['flag' => $flag, 'visit' => $visit, 'amount' => $amount,
                    'daysLeft' => $flag->direction() === Flag::MISSED ? max(0, 365 - $age) : null];
            }
        }
        return $out;
    }

    public function counts(string $from, string $to): array
    {
        return $this->log->counts($from, $to);
    }
}
