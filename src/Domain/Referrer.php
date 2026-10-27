<?php

/**
 * The referring provider a visit's claim will carry, chosen as core's Claim::getReferrerId() chooses it: the
 * Misc Billing Options box 17 provider (on 8.4.0 and later only when its qualifier is empty or DN, referring), then
 * the visit's referring provider, then the patient's Demographics referring provider. When the site sends the
 * rendering provider as the Medicare referrer (MedicareReferrerIsRenderer, with a 1C provider number), the
 * referrer is the rendering provider, which this check leaves alone.
 *
 * Core issue #14858: Demographics lets an authorized user with no NPI be the referring provider, the visit form's
 * list (NPI holders only) then preselects nothing, and Claim::getReferrerId() falls back to the patient's choice,
 * so the 837 goes out with the referrer's name and no NPI ("*** Referring provider has no NPI." in the X12 log).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Domain;

final class Referrer
{
    public const BOX_17 = 'box17';
    public const VISIT = 'visit';
    public const PATIENT = 'patient';

    public function __construct(
        public readonly int $userId,
        public readonly string $source,
        public readonly string $name,
        public readonly string $npi,
    ) {
    }

    /**
     * The users.id the claim takes as its referrer, and where it came from; null when there is none, or when the
     * rendering provider is sent instead.
     *
     * @param bool $qualifierCounts whether box 17's provider counts only with qualifier '' or DN (OpenEMR 8.4.0 and later)
     * @param bool $renderingInstead the site sends the rendering provider as the Medicare referrer for this payer
     * @return array{id: int, source: string}|null
     */
    public static function choose(
        int $box17Provider,
        string $box17Qualifier,
        bool $qualifierCounts,
        int $visitReferrer,
        int $patientReferrer,
        bool $renderingInstead,
    ): ?array {
        if ($box17Provider > 0 && (!$qualifierCounts || in_array(trim($box17Qualifier), ['', 'DN'], true))) {
            return ['id' => $box17Provider, 'source' => self::BOX_17];
        }
        if ($visitReferrer > 0) {
            return ['id' => $visitReferrer, 'source' => self::VISIT];
        }
        if ($renderingInstead) {
            return null;
        }
        return $patientReferrer > 0 ? ['id' => $patientReferrer, 'source' => self::PATIENT] : null;
    }

    /** No NPI on the claim: what core's referrerNPI() would send (x12Clean(trim(npi))) is empty. */
    public function npiMissing(): bool
    {
        return preg_replace('/[^A-Z0-9!"\\&\'()+,\\-.\\/;?=@ ]/', '', strtoupper(trim($this->npi))) === '';
    }
}
