<?php

/**
 * Each flag the checks have found and when it cleared, so the report counts both directions: a flag that cleared
 * after a fee sheet change counts as accepted, one dismissed in the Staff Worklist as dismissed.
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
use OpenHealthcare\RevenueCycle\Domain\Flag;

final class FlagLog
{
    /** Rows per statement: one statement per batch, not per flag, so a view writes (and core audits) a few. */
    public const BATCH = 500;

    /**
     * Records what changed since the last run: a flag found for the first time is added, a cleared flag found again
     * reopens, and an open flag on the scanned visits (and appointments) no longer found is marked cleared. A flag
     * found again and still open is left as it is, so a view that changes nothing writes nothing, and core's audit
     * log, which records each write, doesn't grow with every view.
     *
     * @param list<Flag> $flags      everything the checks found on the scanned visits
     * @param list<int>  $encounters the visits scanned
     */
    public function record(array $flags, array $encounters, \DateTimeImmutable $now): void
    {
        $at = $now->format('Y-m-d H:i:s');
        $byKey = [];
        foreach ($flags as $f) {
            $byKey[$f->key()] ??= $f;
        }
        $keys = array_map('strval', array_keys($byKey));
        $cleared = [];
        foreach (array_chunk($keys, self::BATCH) as $chunk) {
            foreach (QueryUtils::fetchRecords('SELECT item_key, cleared_at FROM revenue_cycle_flag WHERE item_key IN (' . self::marks($chunk) . ')', $chunk) as $r) {
                $cleared[(string)$r['item_key']] = $r['cleared_at'] !== null;
            }
        }
        $new = array_values(array_filter($byKey, fn(Flag $f): bool => !array_key_exists($f->key(), $cleared)));
        foreach (array_chunk($new, self::BATCH) as $chunk) {
            $binds = [];
            foreach ($chunk as $f) {
                array_push($binds, $f->key(), $f->pid, $f->encounter, $f->kind, $f->check(), $f->direction(), substr($f->subject, 0, 191), $at, $at);
            }
            // The duplicate-key clause covers another view recording the same flag in between.
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO revenue_cycle_flag (item_key, pid, encounter, kind, check_no, direction, subject, first_seen, last_seen, cleared_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'))
                . ' ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), cleared_at = NULL',
                $binds
            );
        }
        $reopen = array_keys(array_filter($cleared));
        foreach (array_chunk($reopen, self::BATCH) as $chunk) {
            QueryUtils::sqlStatementThrowException(
                'UPDATE revenue_cycle_flag SET cleared_at = NULL, last_seen = ? WHERE item_key IN (' . self::marks($chunk) . ')',
                [$at, ...$chunk]
            );
        }
        $found = array_flip($keys);
        foreach (array_chunk(array_values(array_unique([...$encounters, 0])), self::BATCH) as $chunk) {
            $gone = [];
            foreach (QueryUtils::fetchRecords(
                'SELECT item_key FROM revenue_cycle_flag WHERE cleared_at IS NULL AND encounter IN (' . self::marks($chunk) . ')',
                $chunk
            ) as $r) {
                if (!isset($found[(string)$r['item_key']])) {
                    $gone[] = (string)$r['item_key'];
                }
            }
            foreach (array_chunk($gone, self::BATCH) as $batch) {
                QueryUtils::sqlStatementThrowException(
                    'UPDATE revenue_cycle_flag SET cleared_at = ? WHERE item_key IN (' . self::marks($batch) . ')',
                    [$at, ...$batch]
                );
            }
        }
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Flags first seen in the period, by check and direction: found, accepted (cleared, not dismissed), dismissed, open.
     *
     * @return list<array{check_no: int, direction: string, found: int, accepted: int, dismissed: int, open: int}>
     */
    public function counts(string $from, string $to): array
    {
        // Dismissals are kept by the Staff Worklist module (another openhealthcare module), in its own table: read
        // only when that table has the two columns this reads. Without the Worklist nothing counts as dismissed.
        $worklist = (int)(QueryUtils::fetchRecords(
            "SELECT COUNT(*) AS n FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'staff_worklist_state' AND column_name IN ('item_key', 'status')"
        )[0]['n'] ?? 0) === 2;
        $dismissed = $worklist ? "EXISTS (SELECT 1 FROM staff_worklist_state AS s WHERE s.item_key = f.item_key AND s.status = 'dismissed')" : '0';
        // A flag counts while its visit is there; an appointment's flag (encounter 0) has none. A deleted visit is
        // never scanned again, so its open flag would otherwise stay open forever.
        $live = 'f.encounter = 0 OR EXISTS (SELECT 1 FROM form_encounter AS fe WHERE fe.pid = f.pid AND fe.encounter = f.encounter AND '
            . VisitSource::live('fe') . ')';
        return array_map(fn(array $r): array => ['check_no' => (int)$r['check_no'], 'direction' => (string)$r['direction'], 'found' => (int)$r['found'],
            'accepted' => (int)$r['accepted'], 'dismissed' => (int)$r['dismissed'], 'open' => (int)$r['found'] - (int)$r['accepted'] - (int)$r['dismissed']],
            QueryUtils::fetchRecords(
                "SELECT f.check_no, f.direction, COUNT(*) AS found,
                        SUM(f.cleared_at IS NOT NULL AND NOT $dismissed) AS accepted, SUM($dismissed) AS dismissed
                   FROM revenue_cycle_flag AS f
                  WHERE f.first_seen >= ? AND f.first_seen < ? AND ($live)
                  GROUP BY f.check_no, f.direction ORDER BY f.check_no, f.direction",
                [$from, $to]
            ));
    }
}
