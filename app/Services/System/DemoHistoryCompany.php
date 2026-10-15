<?php

namespace App\Services\System;

use Carbon\CarbonImmutable;

/**
 * The demonstration company with a year of history, for dashboards and screenshots: its own tenant coded `DEMO-HISTORY`, twelve months
 * of purchases, sales, returns and payments with the seasons of a Pakistani trader (Ramzan, the two Eids, the wedding season, a slow
 * summer), monthly rent and utilities, payroll, attendance, leaves and leads. It is built by {@see PilotDemoCompany} through the
 * application's own API, so every number is posted by the real code, the period lock and validation included. Building twice changes
 * nothing; {@see self::remove()} (which only ever touches the DEMO- prefixed company with this code) starts over.
 */
class DemoHistoryCompany
{
    public const CODE = 'DEMO-HISTORY';

    public const ADMIN_EMAIL = 'history-admin@demo.invalid';

    /**
     * Month number => how much busier (or quieter) the month is than an ordinary one. The dates of Ramzan and the Eids move about
     * eleven days earlier every year; these follow 2026 (Ramzan from mid February, Eid ul Fitr in March, Eid ul Adha at the end of May).
     *
     * @var array<int, float>
     */
    public const SEASONALITY = [1 => 1.0, 2 => 1.45, 3 => 1.6, 4 => 0.9, 5 => 1.25, 6 => 1.1, 7 => 0.85, 8 => 0.85, 9 => 0.95, 10 => 1.0, 11 => 1.05, 12 => 1.2];

    /**
     * @return array<string, mixed>
     */
    public static function profile(int $months = 12, int $salesPerMonth = 36, int $purchasesPerMonth = 8): array
    {
        $towns = ['Karachi, Clifton', 'Lahore, Gulberg', 'Islamabad, F-10', 'Faisalabad, D Ground', 'Rawalpindi, Saddar', 'Peshawar, University Road', 'Multan, Cantt', 'Hyderabad, Latifabad'];
        $fillers = fn (array $names): array => array_map(fn (string $name, int $i): array => [$name, $towns[$i % count($towns)]], $names, array_keys($names));

        return [
            'name' => 'Meezan Wholesale and Retail (12-month demo)', 'address' => 'Main Bazaar, Saddar, Rawalpindi', 'catalogue' => null, 'sku_prefix' => 'MWR',
            'customers' => $fillers(['Karim Brothers', 'Madina Super Store', 'Zam Zam Traders', 'Rizwan Mart', 'Shaheen Kiryana', 'Bilal General Store', 'Fatima Departmental Store', 'Quetta Wholesale', 'Al-Habib Catering', 'Hotel Pearl Supplies']),
            'suppliers' => $fillers(['Haji Younus and Sons', 'Punjab Rice Mills', 'Karachi Oil Depot', 'National Packaging', 'Lahore Sugar Depot']),
            'code' => self::CODE, 'admin_email' => self::ADMIN_EMAIL, 'username' => 'history_admin', 'admin_first' => 'History', 'company_email' => 'info@history.demo.invalid', 'seed' => 91,
            'months' => max(2, $months), 'sales_per_month' => max(1, $salesPerMonth), 'purchases_per_month' => max(1, $purchasesPerMonth),
            'seasonality' => self::SEASONALITY, 'history' => true,
        ];
    }

    /**
     * @return array{created: bool, company_id: int, code: string, email: string, password: string|null, summary: array<string, int>}
     */
    public function build(int $months = 12, int $salesPerMonth = 36, int $purchasesPerMonth = 8, ?CarbonImmutable $today = null): array
    {
        return (new PilotDemoCompany)->buildShowcase(self::profile($months, $salesPerMonth, $purchasesPerMonth), $today);
    }

    public function remove(): bool
    {
        return (new PilotDemoCompany)->remove(self::CODE);
    }
}
