<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\RevenueCycle\Repository\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The settings as stored: check 8 is on for a site that never saved the setting, and follows what was saved after.
 */
final class SettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        QueryUtils::$results = [];
        QueryUtils::$calls = [];
    }

    public function testCheck8IsOnUntilTheSiteSavesItOff(): void
    {
        QueryUtils::$results = [[]];
        self::assertTrue((new Settings())->checkNdc(), 'nothing saved yet');

        QueryUtils::$results = [[['name' => 'days_to_bill', 'value' => '5']]];
        self::assertTrue((new Settings())->checkNdc(), 'other settings saved, this one not');

        QueryUtils::$results = [[['name' => Settings::CHECK_NDC, 'value' => 'false']]];
        self::assertFalse((new Settings())->checkNdc(), 'saved off');

        QueryUtils::$results = [[['name' => Settings::CHECK_NDC, 'value' => 'true']]];
        self::assertTrue((new Settings())->checkNdc(), 'saved on');
    }
}
