<?php

/**
 * Revenue Cycle settings: what the record can't say on its own. The clinic's vaccine list (each CVX with the product
 * line it bills), the provider whose tests are run in the clinic, the visit categories never billed, when the review
 * starts and how many days coding gets before a visit counts as not billed.
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
use OpenHealthcare\RevenueCycle\Http\Guide;
use OpenHealthcare\RevenueCycle\Http\Page;
use OpenHealthcare\RevenueCycle\Http\Request;
use OpenHealthcare\RevenueCycle\Repository\Settings;
use OpenHealthcare\RevenueCycle\Repository\VisitSource;

// The same access as Bootstrap::ACL, written out: tools/footprint.php lists each check it can read.
if (!AclMain::aclCheckCore('acct', 'bill')) {
    OpenEmr::deny('Revenue Cycle: settings refused', xl('Revenue Cycle'), 'Revenue Cycle');
}
$request = Request::get();
$settings = new Settings();
$visits = new VisitSource();
$today = new DateTimeImmutable('today');
$saved = false;
if ($request->isMethod('POST')) {
    OpenEmr::requireCsrf(Request::text($request->request->all(), 'csrf_token_form'));
    $user = (int)OpenEmr::session('authUserID');
    $start = Request::text($request->request->all(), 'start_date');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1) {
        $settings->save(Settings::START_DATE, $start, $user);
    }
    $settings->save(Settings::DAYS_TO_BILL, max(0, min(60, $request->request->getInt('days_to_bill', 3))), $user);
    $settings->save(Settings::NEVER_BILLABLE, array_map('intval', $request->request->all('never_billable')), $user);
    $settings->save(Settings::IN_CLINIC_LABS, array_map('intval', $request->request->all('in_clinic_labs')), $user);
    $settings->save(Settings::CHECK_NDC, $request->request->has('check_ndc'), $user);
    $vaccines = [];
    $cvx = $request->request->all('vaccine_cvx');
    $types = $request->request->all('vaccine_type');
    $codes = $request->request->all('vaccine_code');
    foreach ($cvx as $i => $c) {
        $c = trim((string)$c);
        $code = strtoupper(trim((string)($codes[$i] ?? '')));
        $type = in_array($types[$i] ?? '', ['CPT4', 'HCPCS'], true) ? (string)$types[$i] : 'CPT4';
        if (preg_match('/^\d{1,3}$/', $c) === 1 && preg_match('/^[A-Z0-9]{5}$/', $code) === 1) {
            $vaccines[$c] = ['type' => $type, 'code' => $code];
        }
    }
    $settings->save(Settings::VACCINES, $vaccines, $user);
    OpenEmr::audit('other', 'Revenue Cycle: settings saved');
    $saved = true;
    $settings = new Settings();
}
$start = $settings->startDate($today);
$vaccineList = $settings->vaccines();
// Vaccines given here since the start date that the list doesn't name, so filling it starts from what was given.
$unlisted = QueryUtils::fetchRecords(
    "SELECT i.cvx_code, COUNT(*) AS n FROM immunizations AS i
      WHERE i.information_source = 'new_immunization_record' AND COALESCE(i.added_erroneously, 0) = 0 AND i.cvx_code <> '' AND i.administered_date >= ?
      GROUP BY i.cvx_code ORDER BY n DESC",
    [$start]
);
$unlisted = array_values(array_filter($unlisted, fn(array $r): bool => !isset($vaccineList[trim((string)$r['cvx_code'])])));
$never = array_flip($settings->neverBillable());
$labs = array_flip($settings->inClinicLabs());
$csrf = OpenEmr::csrfToken();
$rows = $vaccineList + array_fill_keys(array_map(fn(array $r): string => trim((string)$r['cvx_code']), $unlisted), ['type' => 'CPT4', 'code' => '']);
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Revenue Cycle settings'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <?php echo \OpenHealthcare\RevenueCycle\Contrast::style(); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 70rem">
    <h1 class="h4"><?php echo xlt('Revenue Cycle settings'); ?></h1>
    <p><a href="index.php"><?php echo xlt('Back to the charge review'); ?></a> · <a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <?php if ($saved) : ?>
        <div class="alert alert-success"><?php echo xlt('Saved.'); ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="start_date"><?php echo xlt('Check visits from'); ?></label>
                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo attr($start); ?>">
            </div>
            <div class="form-group col-md-4">
                <label for="days_to_bill"><?php echo xlt('Days coding gets before a visit counts as not billed'); ?></label>
                <input type="number" min="0" max="60" class="form-control" id="days_to_bill" name="days_to_bill" value="<?php echo attr((string)$settings->daysToBill()); ?>">
            </div>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="check_ndc" name="check_ndc" value="1" aria-describedby="check_ndc_help"<?php echo $settings->checkNdc() ? ' checked' : ''; ?>>
            <label class="form-check-label" for="check_ndc"><?php echo xlt('Check drug J-code lines for an NDC (check 8)'); ?></label>
            <small id="check_ndc_help" class="form-text text-muted"><?php echo xlt('Turn this off if no payer you bill needs an NDC on drug lines.'); ?></small>
        </div>
        <div class="row">
            <fieldset class="col-md-6">
                <legend class="h6"><?php echo xlt('Visit categories never billed (a phone note, a chart update)'); ?></legend>
                <?php foreach ($visits->categories() as $c) : ?>
                    <div class="form-check"><label class="form-check-label">
                        <input class="form-check-input" type="checkbox" name="never_billable[]" value="<?php echo attr((string)$c['id']); ?>"<?php echo isset($never[$c['id']]) ? ' checked' : ''; ?>>
                        <?php echo text(xl_appt_category($c['name'])); ?></label></div>
                <?php endforeach; ?>
            </fieldset>
            <fieldset class="col-md-6">
                <legend class="h6"><?php echo xlt('Procedure providers whose tests are run in the clinic'); ?></legend>
                <?php foreach ($visits->procedureProviders() as $p) : ?>
                    <div class="form-check"><label class="form-check-label">
                        <input class="form-check-input" type="checkbox" name="in_clinic_labs[]" value="<?php echo attr((string)$p['ppid']); ?>"<?php echo isset($labs[$p['ppid']]) ? ' checked' : ''; ?>>
                        <?php echo text($p['name']); ?></label></div>
                <?php endforeach; ?>
            </fieldset>
        </div>
        <h2 class="h5 mt-4"><?php echo xlt('Your vaccine list'); ?></h2>
        <p><?php echo xlt('Each vaccine you stock, by its CVX code, with the code of the product line you bill for it. A vaccine given here with no line for its product is flagged; one not on this list is not, since only you know what you bill for it. The rows with no code yet are vaccines given here since the start date.'); ?></p>
        <table class="table table-sm" style="max-width: 40rem">
            <thead><tr><th scope="col"><?php echo xlt('CVX'); ?></th><th scope="col"><?php echo xlt('Code type'); ?></th><th scope="col"><?php echo xlt('Product code'); ?></th></tr></thead>
            <tbody>
            <?php $i = 0; foreach ($rows + ['' => ['type' => 'CPT4', 'code' => '']] as $cvx => $product) : ?>
                <tr>
                    <td><input class="form-control form-control-sm" name="vaccine_cvx[<?php echo attr((string)$i); ?>]" aria-label="<?php echo attr(sprintf(xl('CVX code, row %d'), $i + 1)); ?>" value="<?php echo attr((string)$cvx); ?>" maxlength="3"></td>
                    <td><select class="form-control form-control-sm" name="vaccine_type[<?php echo attr((string)$i); ?>]" aria-label="<?php echo attr(sprintf(xl('Code type, row %d'), $i + 1)); ?>">
                        <?php foreach (['CPT4', 'HCPCS'] as $t) : ?>
                            <option value="<?php echo attr($t); ?>"<?php echo $product['type'] === $t ? ' selected' : ''; ?>><?php echo text($t); ?></option>
                        <?php endforeach; ?></select></td>
                    <td><input class="form-control form-control-sm" name="vaccine_code[<?php echo attr((string)$i); ?>]" aria-label="<?php echo attr(sprintf(xl('Product code, row %d'), $i + 1)); ?>" value="<?php echo attr($product['code']); ?>" maxlength="5"></td>
                </tr>
                <?php $i++; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn btn-primary"><?php echo xlt('Save'); ?></button>
    </form>
</main>
</body>
</html>
