<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenHealthcare\RevenueCycle\Http\Request;
use PHPUnit\Framework\TestCase;

/** A field as text the same way on Symfony 5.4 (OpenEMR 7.0.x), which has no InputBag::getString(), and later. */
final class RequestTest extends TestCase
{
    public function testAFieldIsTextOrNothing(): void
    {
        $bag = ['from' => '2026-01-01', 'days' => 3, 'list' => ['a', 'b']];
        self::assertSame('2026-01-01', Request::text($bag, 'from'));
        self::assertSame('3', Request::text($bag, 'days'));
        self::assertSame('', Request::text($bag, 'list'), 'a list is not one value');
        self::assertSame('', Request::text($bag, 'missing'));
    }

    public function testNoPageCallsGetString(): void
    {
        $files = 0;
        foreach (['public', 'src'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files++;
                    self::assertStringNotContainsString('->getString(', (string)file_get_contents($file->getPathname()), $file->getFilename() . ' would stop on OpenEMR 7.0.x');
                }
            }
        }
        self::assertGreaterThan(3, $files, 'the pages were found');
    }
}
