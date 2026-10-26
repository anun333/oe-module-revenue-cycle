<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenHealthcare\RevenueCycle\Domain\Flag;
use OpenHealthcare\RevenueCycle\Review\LookBackPage;
use PHPUnit\Framework\TestCase;

/** The look-back list: one check at a time, a page at a time, and CSV cells a spreadsheet won't run. */
final class LookBackPageTest extends TestCase
{
    public function testNoRowsIsOneEmptyPage(): void
    {
        $this->assertSame(['rows' => [], 'page' => 1, 'pages' => 1, 'first' => 0, 'last' => 0, 'total' => 0], LookBackPage::page([], 3));
    }

    public function testExactlyAPageIsOnePage(): void
    {
        $p = LookBackPage::page(range(1, 200), 1);
        $this->assertSame([1, 1, 200, 200], [$p['pages'], $p['first'], $p['last'], count($p['rows'])]);
    }

    public function testOneMoreRowIsASecondPage(): void
    {
        $p = LookBackPage::page(range(1, 201), 2);
        $this->assertSame([2, 2, 201, 201, [201]], [$p['pages'], $p['page'], $p['first'], $p['last'], $p['rows']]);
    }

    public function testAPageOutOfRangeShowsTheNearestOne(): void
    {
        $this->assertSame(2, LookBackPage::page(range(1, 201), 99)['page']);
        $this->assertSame(1, LookBackPage::page(range(1, 201), 0)['page']);
        $this->assertSame(1, LookBackPage::page(range(1, 201), -4)['page']);
    }

    public function testOneCheckOrEvery(): void
    {
        $found = [
            ['flag' => new Flag(Flag::NO_DIAGNOSIS, 1, 7, 'a', ['99213'])],
            ['flag' => new Flag(Flag::MODIFIER_25_ALONE, 2, 7, 'b', ['99213'])],
            ['flag' => new Flag(Flag::NO_DIAGNOSIS, 3, 8, 'c', ['99214'])],
        ];
        $check = $found[0]['flag']->check();
        $this->assertCount(3, LookBackPage::forCheck($found, 0));
        $this->assertCount(2, LookBackPage::forCheck($found, $check));
        $expected = [$check => 2, $found[1]['flag']->check() => 1];
        ksort($expected);
        $this->assertSame($expected, LookBackPage::byCheck($found), 'counts by check, in check order');
    }

    public function testACellAFormulaWouldReadIsKeptAsText(): void
    {
        $this->assertSame("'=HYPERLINK(1)", LookBackPage::cell('=HYPERLINK(1)'));
        $this->assertSame("'+1", LookBackPage::cell('+1'));
        $this->assertSame("'@x", LookBackPage::cell('@x'));
        $this->assertSame('Smith, Ann (PF1)', LookBackPage::cell('Smith, Ann (PF1)'));
        $this->assertSame('90.00', LookBackPage::cell('90.00'));
    }
}
