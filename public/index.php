<?php

/**
 * Revenue Cycle: the module's main page.
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
use OpenEMR\Core\Header;
use OpenHealthcare\Common\OpenEmr;

// The same access as Bootstrap::ACL, written out: tools/footprint.php lists each check it can read.
if (!AclMain::aclCheckCore('acct', 'bill')) {
    OpenEmr::deny('Revenue Cycle: page refused', xl('Revenue Cycle'), 'Revenue Cycle');
}
OpenEmr::audit('other', 'Revenue Cycle: page viewed');
$csrf = OpenEmr::csrfToken();
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Revenue Cycle'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top">
<main class="container-fluid py-3" style="max-width: 70rem">
    <h1 class="h4"><?php echo xlt('Revenue Cycle'); ?></h1>
    <p class="text-muted"><?php echo xlt('Nothing here yet.'); ?></p>
</main>
</body>
</html>
