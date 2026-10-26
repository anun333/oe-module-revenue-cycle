<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\RevenueCycle\Domain\Flag;
use OpenHealthcare\RevenueCycle\Repository\FlagLog;
use PHPUnit\Framework\TestCase;

/**
 * What a run writes to revenue_cycle_flag: only what changed, in batches. Core's audit log records each write, so a
 * view of a busy clinic's tab that rewrote every flag one statement at a time added tens of thousands of audit rows.
 */
final class FlagLogTest extends TestCase
{
    private const NOW = '2026-10-26 09:00:00';

    protected function tearDown(): void
    {
        QueryUtils::$results = [];
        QueryUtils::$calls = [];
    }

    /** @return list<Flag> */
    private static function flags(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = new Flag(Flag::NO_DIAGNOSIS, 1000 + $i, 7, "line:$i", ['99213']);
        }
        return $out;
    }

    /** @return list<array{sql: string, binds: array<mixed>}> */
    private static function writes(): array
    {
        return array_values(array_filter(QueryUtils::$calls, fn(array $c): bool => !str_starts_with(ltrim($c['sql']), 'SELECT')));
    }

    private function record(array $flags, array $encounters): void
    {
        (new FlagLog())->record($flags, $encounters, new \DateTimeImmutable(self::NOW));
    }

    public function testNothingFoundAndNothingOpenWritesNothing(): void
    {
        $this->record([], []);
        $this->assertSame([], self::writes());
        $this->assertCount(1, QueryUtils::$calls, 'one look for open flags on appointments (encounter 0)');
    }

    public function testFiveHundredNewFlagsAreOneStatement(): void
    {
        $this->record(self::flags(500), []);
        $writes = self::writes();
        $this->assertCount(1, $writes);
        $this->assertStringStartsWith('INSERT INTO revenue_cycle_flag', $writes[0]['sql']);
        $this->assertCount(500 * 9, $writes[0]['binds']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $writes[0]['sql']);
    }

    public function testFiveHundredAndOneNewFlagsAreTwoStatements(): void
    {
        $this->record(self::flags(501), []);
        $writes = self::writes();
        $this->assertCount(2, $writes);
        $this->assertCount(500 * 9, $writes[0]['binds']);
        $this->assertCount(9, $writes[1]['binds']);
        $reads = array_values(array_filter(QueryUtils::$calls, fn(array $c): bool => str_contains($c['sql'], 'WHERE item_key IN')));
        $this->assertCount(2, $reads, 'what is stored is read in batches too');
    }

    public function testAFlagStillOpenIsLeftAsItIs(): void
    {
        $flags = self::flags(3);
        QueryUtils::$results = [
            array_map(fn(Flag $f): array => ['item_key' => $f->key(), 'cleared_at' => null], $flags),
            array_map(fn(Flag $f): array => ['item_key' => $f->key()], $flags),
        ];
        $this->record($flags, [1001, 1002, 1003]);
        $this->assertSame([], self::writes(), 'a view that changes nothing writes nothing');
    }

    public function testAClearedFlagFoundAgainReopens(): void
    {
        $flags = self::flags(2);
        QueryUtils::$results = [
            [['item_key' => $flags[0]->key(), 'cleared_at' => null], ['item_key' => $flags[1]->key(), 'cleared_at' => '2026-10-01 08:00:00']],
            [['item_key' => $flags[0]->key()]],
        ];
        $this->record($flags, [1001, 1002]);
        $writes = self::writes();
        $this->assertCount(1, $writes);
        $this->assertStringStartsWith('UPDATE revenue_cycle_flag SET cleared_at = NULL', $writes[0]['sql']);
        $this->assertSame([self::NOW, $flags[1]->key()], $writes[0]['binds']);
    }

    public function testOnlyNewFlagsAreInserted(): void
    {
        $flags = self::flags(3);
        QueryUtils::$results = [[['item_key' => $flags[0]->key(), 'cleared_at' => null]]];
        $this->record($flags, []);
        $writes = self::writes();
        $this->assertCount(1, $writes);
        $this->assertSame([$flags[1]->key(), $flags[2]->key()], [$writes[0]['binds'][0], $writes[0]['binds'][9]]);
    }

    public function testFlagsNoLongerFoundAreClearedInBatches(): void
    {
        $gone = array_map(fn(int $i): array => ['item_key' => "revcycle:7:$i"], range(1, 501));
        QueryUtils::$results = [$gone];
        $this->record([], [1001]);
        $writes = self::writes();
        $this->assertCount(2, $writes);
        $this->assertStringStartsWith('UPDATE revenue_cycle_flag SET cleared_at = ?', $writes[0]['sql']);
        $this->assertCount(501, $writes[0]['binds']);
        $this->assertSame([self::NOW, 'revcycle:7:501'], $writes[1]['binds']);
    }

    public function testTheSameFlagTwiceInARunIsWrittenOnce(): void
    {
        $flags = self::flags(1);
        $this->record([$flags[0], $flags[0]], []);
        $this->assertCount(1, self::writes());
        $this->assertCount(9, self::writes()[0]['binds']);
    }
}
