<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenHealthcare\Common\TranslatedStrings;
use PHPUnit\Framework\TestCase;

/**
 * OpenEMR's xl() shows an apostrophe or a quote mark in a translated string as a backtick, so every
 * string the module translates is written without them.
 */
final class TranslatedStringsTest extends TestCase
{
    public function testNoTranslatedStringHasAnApostropheOrQuoteMark(): void
    {
        $result = TranslatedStrings::scan(dirname(__DIR__));
        self::assertGreaterThan(2, $result['checked'], 'the scan found the translated strings');
        self::assertSame([], $result['found']);
    }
}
