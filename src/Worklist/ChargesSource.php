<?php

/**
 * "Charges to check" in the Staff Worklist: each flag on a visit whose claim hasn't gone out, with the fee sheet a
 * click away. Accepting means fixing the fee sheet, after which the flag clears; dismissing takes a reason, which the
 * Worklist logs. Nothing here changes a claim.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Worklist;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\RevenueCycle\Domain\Flag;
use OpenHealthcare\RevenueCycle\Labels;
use OpenHealthcare\RevenueCycle\Review\ChargeReview;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Source\WorkSourceInterface;

final class ChargesSource implements WorkSourceInterface
{
    public function __construct(private readonly \DateTimeImmutable $now, private readonly ChargeReview $review = new ChargeReview())
    {
    }

    public function id(): string
    {
        return Flag::SOURCE;
    }

    public function collect(?int $pid = null): array
    {
        $queue = $this->review->queue($this->now->setTime(0, 0), $pid);
        if ($queue === []) {
            return [];
        }
        $pids = array_values(array_unique(array_map(fn(array $q): int => $q['flag']->pid, $queue)));
        $patients = [];
        foreach (array_chunk($pids, 500) as $chunk) {
            foreach (QueryUtils::fetchRecords('SELECT pd.pid, ' . PatientRef::SQL_COLUMNS . ' FROM patient_data AS pd WHERE pd.pid IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) {
                $patients[(int)$row['pid']] = $row;
            }
        }
        $items = [];
        foreach ($queue as $q) {
            $flag = $q['flag'];
            $visit = $q['visit'];
            if (!isset($patients[$flag->pid])) {
                continue;
            }
            $items[] = new WorkItem(
                key: new ItemKey(Flag::SOURCE, $flag->pid, $flag->ref()),
                patient: PatientRef::fromRow($flag->pid, $patients[$flag->pid], $this->now),
                title: Labels::flag($flag, 'xl'),
                detail: implode(' · ', [Labels::direction($flag->direction(), 'xl'), sprintf(xl('Check %d'), $flag->check()), sprintf(xl('Visit of %s'), $q['date'])]),
                priority: $flag->direction() === Flag::OVER ? WorkItem::PRIORITY_HIGH : WorkItem::PRIORITY_NORMAL,
                since: new \DateTimeImmutable($q['date']),
                ownerId: null,
                actionLabel: $visit === null ? xl('Open chart') : xl('Open the visit'),
                actionUrl: $visit === null ? '' : '/interface/patient_file/encounter/encounter_top.php?set_encounter=' . $visit->encounter,
                actionMode: 'chart',
                dismissible: true,
                siteId: $visit === null || $visit->facilityId === 0 ? null : $visit->facilityId,
                sensitivity: $visit === null || $visit->sensitivity === '' ? null : $visit->sensitivity,
            );
        }
        return $items;
    }
}
