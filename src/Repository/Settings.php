<?php

/**
 * The site's settings for the charge review: what the record can't say on its own.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\RevenueCycle\Repository;

use OpenEMR\Common\Database\QueryUtils;

final class Settings
{
    public const START_DATE = 'start_date';
    public const DAYS_TO_BILL = 'days_to_bill';
    public const NEVER_BILLABLE = 'never_billable';
    public const IN_CLINIC_LABS = 'in_clinic_labs';
    public const VACCINES = 'vaccines';
    public const CHECK_NDC = 'check_ndc';

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /** The first visit date the queue reads: the first of the year until the site sets one. */
    public function startDate(\DateTimeImmutable $today): string
    {
        $value = (string)($this->all()[self::START_DATE] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $today->format('Y') . '-01-01';
    }

    public function daysToBill(): int
    {
        $days = $this->all()[self::DAYS_TO_BILL] ?? 3;
        return is_numeric($days) ? max(0, min(60, (int)$days)) : 3;
    }

    /** @return list<int> */
    public function neverBillable(): array
    {
        return self::ints($this->all()[self::NEVER_BILLABLE] ?? []);
    }

    /** @return list<int> */
    public function inClinicLabs(): array
    {
        return self::ints($this->all()[self::IN_CLINIC_LABS] ?? []);
    }

    /** Whether check 8 runs (a J-code drug line with no NDC): on until the site turns it off. */
    public function checkNdc(): bool
    {
        return ($this->all()[self::CHECK_NDC] ?? true) !== false;
    }

    /** @return array<string, array{type: string, code: string}> the clinic's vaccine list: CVX => the product line it bills */
    public function vaccines(): array
    {
        $out = [];
        foreach ((array)($this->all()[self::VACCINES] ?? []) as $cvx => $product) {
            if (is_array($product) && is_string($product['type'] ?? null) && is_string($product['code'] ?? null) && $product['code'] !== '') {
                $out[(string)$cvx] = ['type' => $product['type'], 'code' => $product['code']];
            }
        }
        return $out;
    }

    public function save(string $name, mixed $value, int $userId): void
    {
        QueryUtils::sqlStatementThrowException(
            'REPLACE INTO revenue_cycle_setting (name, value, updated_by) VALUES (?, ?, ?)',
            [$name, json_encode($value), $userId]
        );
        $this->values = null;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if ($this->values === null) {
            $this->values = [];
            foreach (QueryUtils::fetchRecords('SELECT name, value FROM revenue_cycle_setting') as $r) {
                $this->values[(string)$r['name']] = json_decode((string)$r['value'], true);
            }
        }
        return $this->values;
    }

    /** @return list<int> */
    private static function ints(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map('intval', $value), fn(int $i): bool => $i > 0)) : [];
    }
}
