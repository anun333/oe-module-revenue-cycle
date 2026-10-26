<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenHealthcare\Common\GuideLabels;
use OpenHealthcare\RevenueCycle\Http\Guide;
use PHPUnit\Framework\TestCase;

/**
 * GUIDE.md quotes the labels staff click in bold; each must be a string the module translates, or be named in
 * composer.json with the file it comes from (the three directions, which src/Labels.php translates through a
 * variable). Help opens the guide at the installed version's tag.
 */
final class GuideTest extends TestCase
{
    public function testEveryLabelTheGuideQuotesIsOnThePages(): void
    {
        $result = GuideLabels::check(dirname(__DIR__));
        self::assertGreaterThan(20, $result['labels'], 'the guide quotes its labels');
        self::assertGreaterThan(30, $result['strings'], 'the translated strings were read');
        self::assertSame([], $result['missing']);
    }

    public function testHelpOpensTheGuideAtThisVersionsTag(): void
    {
        $v_major = $v_minor = $v_patch = '';
        include dirname(__DIR__) . '/version.php';
        self::assertSame("https://github.com/anun333/oe-module-revenue-cycle/blob/v$v_major.$v_minor.$v_patch/GUIDE.md", Guide::url());
        self::assertSame('https://github.com/anun333/oe-module-revenue-cycle/blob/main/GUIDE.md', Guide::url('/nonexistent/version.php'));
    }
}
