<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenHealthcare\RevenueCycle\Domain\Flag;
use OpenHealthcare\RevenueCycle\Labels;
use PHPUnit\Framework\TestCase;

/**
 * Every kind of flag has its sentence, filled in, with no apostrophe or quote mark (OpenEMR's xl() shows one as a
 * backtick); and no sentence tells anyone to add documentation.
 */
final class LabelsTest extends TestCase
{
    /** A sentence that asks someone to add to the record, such as "Add a note to support the charge". */
    private const ASKS_FOR_DOCUMENTATION = '/\b(add|write|complete|amend|update|addend)\b[^.]*\b(note|documentation|record|chart)\b/i';

    public function testTheDocumentationCheckCatchesARequest(): void
    {
        self::assertMatchesRegularExpression(self::ASKS_FOR_DOCUMENTATION, 'Add a note to support the charge');
        self::assertDoesNotMatchRegularExpression(self::ASKS_FOR_DOCUMENTATION, 'Documented 5 days ago, with no charge on the fee sheet');
    }

    public function testEveryFlagKindSaysWhatWasFound(): void
    {
        $seen = [];
        foreach (array_keys(Flag::KINDS) as $kind) {
            $template = '';
            $text = Labels::flag(new Flag($kind, 501, 7, 'x', ['A1', 'B2']), function (string $s) use (&$template): string {
                $template = $s;
                return $s;
            });
            self::assertStringNotContainsString('%', $text, "$kind is filled in");
            self::assertDoesNotMatchRegularExpression("/['\"`]/", $template, "$kind has no apostrophe or quote mark");
            self::assertDoesNotMatchRegularExpression(self::ASKS_FOR_DOCUMENTATION, $template, "$kind asks for no documentation");
            $seen[$template] = true;
        }
        self::assertCount(count(Flag::KINDS), $seen, 'each kind its own sentence');
        foreach ([Flag::MISSED, Flag::OVER, Flag::QUALITY] as $direction) {
            self::assertNotSame('', Labels::direction($direction, fn(string $s): string => $s));
        }
    }
}
