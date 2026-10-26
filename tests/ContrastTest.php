<?php

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Tests;

use OpenHealthcare\RevenueCycle\Contrast;
use PHPUnit\Framework\TestCase;

final class ContrastTest extends TestCase
{
    public function testTheLightThemesTakeTheDarkerBlue(): void
    {
        foreach (['/openemr/public/themes/style_light.css?v=82', '/public/themes/rtl_style_manila.css?v=1', 'style_light.css', ''] as $header) {
            $style = Contrast::style($header);
            self::assertStringContainsString('#0056b3', $style, $header);
            self::assertStringNotContainsString('#007bff', $style, $header);
        }
    }

    public function testOtherThemesKeepTheirOwnColours(): void
    {
        foreach (['/openemr/public/themes/style_dark.css?v=82', 'rtl_style_solar.css', 'style_cobalt_blue.css', 'style_forest_green.css'] as $header) {
            self::assertSame('', Contrast::style($header), $header);
        }
    }

    public function testEveryColourItSetsMeetsTheAaRatio(): void
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static fn(string $pair): float => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2));
            $linear = array_map(static fn(float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);
            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };
        $ratio = static function (string $a, string $b) use ($luminance): float {
            [$light, $dark] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];
            return ($light + 0.05) / ($dark + 0.05);
        };
        // Foreground on the backgrounds module pages use (white, the light theme's greys, the warning cell).
        foreach (['#0056b3', '#004085', '#4b5563', '#b02a37'] as $text) {
            foreach (['#ffffff', '#f9fafb', '#f3f4f6', '#ffeeba'] as $background) {
                self::assertGreaterThanOrEqual(4.5, $ratio($text, $background), "$text on $background");
            }
        }
        self::assertGreaterThanOrEqual(4.5, $ratio('#ffffff', '#0056b3'), 'white on the button');
    }
}
