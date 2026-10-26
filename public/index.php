<?php

/**
 * Revenue Cycle: the charge review's look-back over billed visits, both directions, and what became of each flag.
 * The queue of visits not yet billed is the Staff Worklist's "Charges to check" tab.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\Header;
use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\RevenueCycle\Domain\Flag;
use OpenHealthcare\RevenueCycle\Http\Request;
use OpenHealthcare\RevenueCycle\Labels;
use OpenHealthcare\RevenueCycle\Review\ChargeReview;

// The same access as Bootstrap::ACL, written out: tools/footprint.php lists each check it can read.
if (!AclMain::aclCheckCore('acct', 'bill')) {
    OpenEmr::deny('Revenue Cycle: page refused', xl('Revenue Cycle'), 'Revenue Cycle');
}
$request = Request::get();
$today = new DateTimeImmutable('today');
$valid = fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$from = Request::text($request->query->all(), 'from');
$to = Request::text($request->query->all(), 'to');
$from = $valid($from) ? $from : $today->format('Y') . '-01-01';
$to = $valid($to) ? $to : $today->format('Y-m-d');
$toExclusive = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
$review = new ChargeReview();
$found = $review->lookBack($from, $toExclusive, $today);
$counts = $review->counts($from, $toExclusive);
OpenEmr::audit('other', 'Revenue Cycle: look-back viewed');

// Who is visible: a visit marked with a sensitivity shows only to users core's own check allows.
$found = array_values(array_filter($found, fn(array $f): bool => $f['visit']->sensitivity === '' || AclMain::aclCheckCore('sensitivities', $f['visit']->sensitivity)));
$names = [];
$pids = array_values(array_unique(array_map(fn(array $f): int => $f['flag']->pid, $found)));
foreach (array_chunk($pids, 500) as $chunk) {
    foreach (QueryUtils::fetchRecords('SELECT pid, fname, lname, pubpid FROM patient_data WHERE pid IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
        $names[(int)$r['pid']] = trim($r['lname'] . ', ' . $r['fname'], ', ') . ' (' . $r['pubpid'] . ')';
    }
}
$totals = [];
foreach ($found as $f) {
    $d = $f['flag']->direction();
    $totals[$d]['n'] = ($totals[$d]['n'] ?? 0) + 1;
    $totals[$d]['amount'] = ($totals[$d]['amount'] ?? 0) + ($f['amount'] ?? 0);
}
$money = fn(?float $a): string => $a === null ? '' : number_format($a, 2);
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Revenue Cycle'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenHealthcare\RevenueCycle\Contrast::style(); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 80rem">
    <h1 class="h4"><?php echo xlt('Revenue Cycle: charge review'); ?></h1>
    <p><?php echo xlt('Visits not yet billed are checked in the Staff Worklist, on the Charges to check tab. This page looks back over visits already billed, in both directions: care in the record that no charge bills, and charges the record does not support.'); ?>
        <a href="settings.php"><?php echo xlt('Settings'); ?></a></p>

    <form method="get" class="form-inline mb-3">
        <label for="from" class="mr-2"><?php echo xlt('From'); ?></label>
        <input type="date" id="from" name="from" class="form-control mr-2" value="<?php echo attr($from); ?>">
        <label for="to" class="mr-2"><?php echo xlt('To'); ?></label>
        <input type="date" id="to" name="to" class="form-control mr-2" value="<?php echo attr($to); ?>">
        <button type="submit" class="btn btn-secondary"><?php echo xlt('Show'); ?></button>
    </form>

    <p class="small text-muted"><?php echo xlt('Amounts are charges from your own fee schedule, not expected payments: a health center visit is paid one rate, and a sliding fee patient pays the discounted amount. A missed charge can be added only where the record already supports it, and for Medicare only within a year of the visit. A charge with nothing behind it on a paid claim goes to whoever handles compliance at your clinic; this module never refunds or reports anything to a payer.'); ?></p>

    <h2 class="h5"><?php echo text(sprintf(xl('Found on billed visits: %d'), count($found))); ?></h2>
    <ul>
        <?php foreach ([Flag::MISSED, Flag::OVER, Flag::QUALITY] as $d) : ?>
            <li><?php echo text(Labels::direction($d, 'xl') . ': ' . ($totals[$d]['n'] ?? 0) . (($totals[$d]['amount'] ?? 0) > 0 ? ' (' . $money($totals[$d]['amount']) . ')' : '')); ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($found !== []) : ?>
        <table class="table table-sm">
            <thead><tr><th><?php echo xlt('Patient'); ?></th><th><?php echo xlt('Visit'); ?></th><th><?php echo xlt('Check'); ?></th><th><?php echo xlt('Found'); ?></th>
                <th class="text-right"><?php echo xlt('Charge'); ?></th><th class="text-right"><?php echo xlt('Days left to correct'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($found as $f) : ?>
                <tr><td><?php echo text($names[$f['flag']->pid] ?? (string)$f['flag']->pid); ?></td><td><?php echo text($f['visit']->date); ?></td>
                    <td><?php echo text($f['flag']->check() . ' · ' . Labels::direction($f['flag']->direction(), 'xl')); ?></td>
                    <td><?php echo text(Labels::flag($f['flag'], 'xl')); ?></td>
                    <td class="text-right"><?php echo text($money($f['amount'])); ?></td>
                    <td class="text-right"><?php echo text($f['daysLeft'] === null ? '' : (string)$f['daysLeft']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 class="h5 mt-4"><?php echo xlt('What became of the flags first found in this period'); ?></h2>
    <p class="small text-muted"><?php echo xlt('Accepted: the finding cleared after the fee sheet changed. Dismissed: dismissed in the Staff Worklist, with its reason. Both directions are counted, as an internal audit should be.'); ?></p>
    <table class="table table-sm" style="max-width: 50rem">
        <thead><tr><th><?php echo xlt('Check'); ?></th><th><?php echo xlt('Direction'); ?></th><th class="text-right"><?php echo xlt('Found'); ?></th>
            <th class="text-right"><?php echo xlt('Accepted'); ?></th><th class="text-right"><?php echo xlt('Dismissed'); ?></th><th class="text-right"><?php echo xlt('Open'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($counts as $c) : ?>
            <tr><td><?php echo text((string)$c['check_no']); ?></td><td><?php echo text(Labels::direction($c['direction'], 'xl')); ?></td>
                <td class="text-right"><?php echo text((string)$c['found']); ?></td><td class="text-right"><?php echo text((string)$c['accepted']); ?></td>
                <td class="text-right"><?php echo text((string)$c['dismissed']); ?></td><td class="text-right"><?php echo text((string)$c['open']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>
