<?php

/**
 * What each flag says, in words, through OpenEMR's translation. A flag names what was found and where; it never
 * names a code to add, and never asks for documentation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle;

use OpenHealthcare\RevenueCycle\Domain\Flag;
use OpenHealthcare\RevenueCycle\Domain\Referrer;

final class Labels
{
    /** @param callable(string): string $translate xl, or the identity in tests */
    public static function flag(Flag $flag, callable $translate): string
    {
        $template = match ($flag->kind) {
            Flag::SEEN_NOT_BILLED => 'Documented %s days ago, with no charge on the fee sheet',
            Flag::SEEN_NO_VISIT => 'Appointment marked seen on %s, with no visit for that day',
            Flag::VACCINE_NO_PRODUCT => 'Vaccine CVX %1$s given, with no line for %2$s, the product your vaccine list bills for it',
            Flag::VACCINE_NO_ADMINISTRATION => 'Vaccines given: %s, with no administration line',
            Flag::TEST_NOT_BILLED => 'In-clinic test %s resulted, with no line for it',
            Flag::VACCINE_BILLED_NONE_GIVEN => '%s billed, with no vaccine recorded as given on this visit',
            Flag::TEST_BILLED_NO_RESULT => '%s billed, and its in-clinic order has no result',
            Flag::CHARGES_NO_DOCUMENTATION => 'Charges: %s, with no forms or signature on the visit',
            Flag::SAME_CODE_TWICE => '%1$s on %2$s lines; units would carry a count',
            Flag::MODIFIER_25_ALONE => '%s with modifier 25, and no other service on the visit',
            Flag::NO_DIAGNOSIS => '%s has no diagnosis pointer',
            Flag::J_CODE_NO_NDC => '%s, a drug billed by its J code, has no NDC',
            Flag::REFERRER_NO_NPI => '%1$s, the referring provider on the claim (from %2$s), has no NPI',
        };
        $args = $flag->args;
        if ($flag->kind === Flag::REFERRER_NO_NPI && isset($args[1])) {
            // Where the claim takes its referrer from, translated before the sentence.
            $args[1] = $translate(match ($args[1]) {
                Referrer::BOX_17 => 'Misc Billing Options box 17',
                Referrer::VISIT => 'the visit form',
                default => 'Demographics',
            });
        }
        return vsprintf($translate($template), $args);
    }

    /** @param callable(string): string $translate */
    public static function direction(string $direction, callable $translate): string
    {
        return $translate(match ($direction) {
            Flag::MISSED => 'Missed charge',
            Flag::OVER => 'Charge with nothing behind it',
            default => 'Claim quality',
        });
    }
}
