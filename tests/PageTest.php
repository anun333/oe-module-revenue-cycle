<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenHealthcare\RevenueCycle\Http\Page;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testWithNoSessionThePageIsEnglishLeftToRight(): void
    {
        self::assertSame('en', Page::lang());
        self::assertSame('ltr', Page::dir());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnOlderLibraryLoadedFirstGivesEnglishInsteadOfStoppingThePage(): void
    {
        // What a site with hand-copied modules can load: openhealthcare-common before 1.4.0, without pageLang().
        eval('namespace OpenHealthcare\Common; final class OpenEmr { public static function webRoot(): string { return ""; } }');

        self::assertSame('en', Page::lang());
        self::assertSame('ltr', Page::dir());
    }

    public function testEveryPageDeclaresItsLanguageFromTheUser(): void
    {
        foreach (glob(__DIR__ . '/../public/*.php') ?: [] as $page) {
            $source = (string)file_get_contents($page);
            if (!str_contains($source, '<html')) {
                continue;
            }
            self::assertStringNotContainsString('<html lang="en">', $source, basename($page));
            self::assertStringContainsString('lang="<?php echo attr(Page::lang()); ?>"', $source, basename($page));
        }
    }

    public function testTheVaccineTableNamesEachControl(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../public/settings.php');
        // Each control is on one line of the template; PHP tags inside it rule out a simple tag pattern.
        $lines = array_values(array_filter(explode("\n", $source), static fn(string $l): bool => (bool)preg_match('/name="vaccine_(?:cvx|type|code)\[/', $l)));
        self::assertCount(3, $lines, 'the three controls of a vaccine row');
        foreach ($lines as $line) {
            self::assertStringContainsString('aria-label=', $line, trim($line));
        }
    }
}
