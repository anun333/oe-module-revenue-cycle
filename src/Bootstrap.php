<?php

/**
 * What Revenue Cycle adds to OpenEMR: a menu entry for users with the access it needs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Menu\MenuEvent;
use OpenHealthcare\Common\Contract\Worklist\Worklist;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;
use OpenHealthcare\RevenueCycle\Worklist\ChargesSource;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-revenue-cycle';
    /** The access a user needs for the menu entry and the page: section and value, as AclMain checks them. */
    public const ACL = ['acct', 'bill'];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
        // The Staff Worklist asks for other modules' tabs by this name, so this module needn't load its classes.
        $this->eventDispatcher->addListener('staff_worklist.collect_sources', $this->addWorklistTab(...));
    }

    /**
     * "Charges to check" in the Staff Worklist (0.8.0 or later), for whoever bills (OpenEMR's billing permission), as Care
     * Management's tab is. The tab is built on openhealthcare-common's contract; where a module copied in by hand loaded
     * an older copy of the library first, the contract isn't there and the tab is left out rather than stop the page.
     */
    public function addWorklistTab(object $event): void
    {
        if (!interface_exists(WorkSource::class) || !AclMain::aclCheckCore('acct', 'bill')) {
            return;
        }
        Worklist::contribute($event, new ChargesSource(Worklist::now($event)), xl('Charges to check'), xl('Biller'));
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'revenu';
        $item->menu_id = 'revenu0';
        $item->label = xlt('Revenue Cycle');
        $item->url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = self::ACL;
        $item->global_req = [];
        // After Messages, or at the end.
        $menu = $event->getMenu();
        $position = count($menu);
        foreach ($menu as $i => $entry) {
            if (($entry->menu_id ?? '') === 'msg0') {
                $position = (int)$i + 1;
                break;
            }
        }
        array_splice($menu, $position, 0, [$item]);
        $event->setMenu($menu);
        return $event;
    }
}
