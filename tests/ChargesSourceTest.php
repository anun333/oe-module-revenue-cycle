<?php

/**
 * "Charges to check" as the Staff Worklist collects it. The tab is built on openhealthcare-common's Worklist contract,
 * so it's tested here without the Worklist installed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace {
    if (!function_exists('xl')) {
        function xl(string $text): string
        {
            return $text;
        }
    }
}

namespace OpenHealthcare\RevenueCycle\Tests {

    use OpenEMR\Common\Database\QueryUtils;
    use OpenHealthcare\Common\Contract\Worklist\WorkItem;
    use OpenHealthcare\Common\Contract\Worklist\Worklist;
    use OpenHealthcare\RevenueCycle\Domain\Flag;
    use OpenHealthcare\RevenueCycle\Worklist\ChargesSource;
    use PHPUnit\Framework\TestCase;

    final class ChargesSourceTest extends TestCase
    {
        private const NOW = '2026-10-26 09:00:00';

        protected function setUp(): void
        {
            QueryUtils::$results = [];
            QueryUtils::$calls = [];
        }

        public function testAnAppointmentSeenWithNoVisitIsAnItemForThatPatient(): void
        {
            QueryUtils::$results = [
                [], // settings: the defaults
                [], // code types
                [], // visits: none
                [['option_id' => '<', 'title' => 'In exam room', 'toggle_setting_1' => 0, 'toggle_setting_2' => 0]], // a seen status
                [['pc_eid' => 55, 'pc_pid' => 7, 'pc_eventDate' => '2026-10-20', 'pc_facility' => 3, 'pc_aid' => 2]],
                [['pid' => 7, 'fname' => 'Jan', 'lname' => 'Doe', 'DOB' => '1980-02-03', 'pubpid' => 'P7', 'deceased_date' => null, 'portal_access' => 0]],
            ];
            $items = (new ChargesSource(new \DateTimeImmutable(self::NOW)))->collect(7);

            $this->assertCount(1, $items);
            $item = $items[0];
            $this->assertInstanceOf(WorkItem::class, $item);
            $flag = new Flag(Flag::SEEN_NO_VISIT, 0, 7, 'appt:55', ['2026-10-20']);
            $this->assertSame('revcycle:7:' . $flag->ref(), (string)$item->key);
            $this->assertStringContainsString('2026-10-20', $item->title);
            $this->assertSame([WorkItem::PRIORITY_NORMAL, 'Doe, Jan', '2026-10-20', 'chart', null], [$item->priority, $item->patient->name, $item->since->format('Y-m-d'), $item->actionMode, $item->siteId]);
            $this->assertSame([7], QueryUtils::$calls[5]['binds'], 'the patient read for the item');
            $this->assertCount(6, QueryUtils::$calls, 'one patient checked on its own records no flags');
        }

        public function testNothingToCheckReadsNoPatients(): void
        {
            $this->assertSame([], (new ChargesSource(new \DateTimeImmutable(self::NOW)))->collect(7));
            $this->assertStringNotContainsString('patient_data', implode(' ', array_column(QueryUtils::$calls, 'sql')));
        }

        public function testTheTabReachesAWorklistThroughTheContract(): void
        {
            $event = new class (new \DateTimeImmutable(self::NOW)) {
                /** @var list<array{object, string, string}> */
                public array $got = [];

                public function __construct(public readonly \DateTimeImmutable $now)
                {
                }

                public function contribute(object $source, string $label, string $ownerRole): void
                {
                    $this->got[] = [$source, $label, $ownerRole];
                }
            };
            $this->assertTrue(Worklist::contribute($event, new ChargesSource(Worklist::now($event)), 'Charges to check', 'Biller'));
            $this->assertSame(Flag::SOURCE, $event->got[0][0]->id());
        }
    }
}
