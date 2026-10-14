<?php

namespace App\Services\System;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * A showcase of several demo companies, each with its own name, people, catalogue and months of purchases, sales, returns, payments and
 * payroll, so a platform dashboard and a company dashboard have something real to draw. Every one is a separate tenant coded
 * `DEMO-SHOW-n` and is built by {@see PilotDemoCompany} through the application's own API, so the figures are posted by the real code.
 * Running it again finds the companies and changes nothing; only removing (which refuses a company that is not a showcase one) starts over.
 */
class ShowcaseDemoCompanies
{
    public const PREFIX = 'DEMO-SHOW-';

    /**
     * @return list<array<string, mixed>>
     */
    public static function profiles(): array
    {
        $grocery = null; // the pilot's own grocery catalogue

        $electronics = [
            'phone' => ['Samsung Galaxy A15 128 GB', 'pc', 41000, 45500], 'charger' => ['Fast Charger 25 W', 'pc', 1300, 1750], 'cable' => ['Type-C Cable 1 m', 'pc', 250, 400],
            'earbuds' => ['Wireless Earbuds', 'pc', 2800, 3900], 'powerbank' => ['Power Bank 10000 mAh', 'pc', 3600, 4700], 'case' => ['Phone Back Case', 'pc', 220, 450],
            'glass' => ['Tempered Glass Protector', 'pc', 90, 250], 'speaker' => ['Bluetooth Speaker', 'pc', 4200, 5600], 'ups' => ['Mini UPS 12 V', 'pc', 3100, 4100],
            'bulb' => ['LED Bulb 12 W', 'pc', 260, 380],
        ];
        $garments = [
            'shirt' => ['Cotton Shirt (men)', 'pc', 1150, 1750], 'kameez' => ['Lawn Kameez (ladies, unstitched)', 'pc', 2100, 2950], 'shalwar' => ['Wash and Wear Shalwar Kameez', 'pc', 2300, 3200],
            'jeans' => ['Denim Jeans', 'pc', 1800, 2650], 'tee' => ['Polo T-shirt', 'pc', 700, 1190], 'hoodie' => ['Fleece Hoodie', 'pc', 1900, 2800],
            'shawl' => ['Winter Shawl', 'pc', 1500, 2300], 'socks' => ['Cotton Socks (dozen)', 'ctn', 720, 1080], 'cap' => ['Baseball Cap', 'pc', 250, 450], 'belt' => ['Leather Belt', 'pc', 500, 850],
        ];
        $pharmacy = [
            'panadol' => ['Panadol 500 mg (box)', 'pc', 130, 165], 'brufen' => ['Brufen 400 mg (box)', 'pc', 190, 240], 'calpol' => ['Calpol Syrup 120 ml', 'pc', 105, 140],
            'augmentin' => ['Augmentin 625 mg', 'pc', 520, 640], 'vitc' => ['Vitamin C 500 mg (bottle)', 'pc', 310, 420], 'ors' => ['ORS Sachet (box of 10)', 'pc', 90, 130],
            'mask' => ['Surgical Masks (box of 50)', 'pc', 180, 300], 'sanitizer' => ['Hand Sanitizer 250 ml', 'pc', 140, 220], 'bp' => ['Digital BP Monitor', 'pc', 3300, 4500],
            'thermo' => ['Digital Thermometer', 'pc', 280, 450],
        ];

        $fillers = fn (array $names): array => array_map(fn (string $name, int $i): array => [$name, ['Karachi, Clifton', 'Lahore, Gulberg', 'Islamabad, F-10', 'Faisalabad, D Ground', 'Rawalpindi, Saddar', 'Peshawar, University Road', 'Multan, Cantt', 'Hyderabad, Latifabad'][$i % 8]], $names, array_keys($names));

        return [
            [
                'name' => 'Al-Noor Kiryana and General Store (demo)', 'address' => 'Main Market, Gulshan-e-Iqbal, Karachi', 'catalogue' => $grocery, 'sku_prefix' => 'ANK',
                'customers' => $fillers(['Karim Brothers', 'Madina Super Store', 'Zam Zam Traders', 'Rizwan Mart', 'Shaheen Kiryana', 'Bilal General Store', 'Fatima Departmental Store', 'Quetta Wholesale']),
                'suppliers' => $fillers(['Haji Younus and Sons', 'Punjab Rice Mills', 'Karachi Oil Depot', 'National Packaging']),
            ],
            [
                'name' => 'Techno Hub Mobile and Electronics (demo)', 'address' => 'Hafeez Centre, Gulberg, Lahore', 'catalogue' => $electronics, 'sku_prefix' => 'THM',
                'customers' => $fillers(['Ali Mobile Corner', 'Smart Zone', 'Digital Point', 'City Communications', 'Gadget Galaxy', 'Mobile Mart Rawalpindi', 'Hamza Electronics']),
                'suppliers' => $fillers(['Samsung Distribution Pakistan', 'Anker Authorized Importer', 'Lahore Accessories Wholesale']),
            ],
            [
                'name' => 'Lahore Garments and Fashion House (demo)', 'address' => 'Liberty Market, Gulberg III, Lahore', 'catalogue' => $garments, 'sku_prefix' => 'LGF',
                'customers' => $fillers(['Fashion Point', 'Style Centre', 'Mehar Boutique', 'Royal Garments', 'Urban Wear', 'Family Fashion', 'Sapphire Outlet Partner']),
                'suppliers' => $fillers(['Faisalabad Textile Mills', 'Gul Ahmed Wholesale Counter', 'Sialkot Denim Works']),
            ],
            [
                'name' => 'Shifa Medical and General Pharmacy (demo)', 'address' => 'Saddar, Rawalpindi', 'catalogue' => $pharmacy, 'sku_prefix' => 'SMP',
                'customers' => $fillers(['Al-Shifa Clinic', 'City Hospital Pharmacy', 'Noor Medical Store', 'Mother and Child Care', 'Dr. Kamran Clinic', 'Green Cross Medical']),
                'suppliers' => $fillers(['Getz Pharma Distributor', 'Sami Pharma Wholesale', 'Medipak Supplies']),
            ],
            [
                'name' => 'Karachi Digital Bazaar (demo)', 'address' => 'Saddar Electronics Market, Karachi', 'catalogue' => $electronics, 'sku_prefix' => 'KDB',
                'customers' => $fillers(['Sindh Mobiles', 'Bahria Gadgets', 'PECHS Electronics', 'Nazimabad Mobile Hub', 'Korangi Tech', 'Orangi Mobile Point']),
                'suppliers' => $fillers(['Karachi Import Traders', 'Global Accessories Pakistan', 'Shams Distribution']),
            ],
            [
                'name' => 'Islamabad Daily Needs Mart (demo)', 'address' => 'Sector G-9 Markaz, Islamabad', 'catalogue' => $grocery, 'sku_prefix' => 'IDN',
                'customers' => $fillers(['Margalla Residency', 'Blue Area Offices Canteen', 'Hotel Serena Supplies', 'Embassy Staff Club', 'F-7 Family Mart', 'Kohsar Super Store']),
                'suppliers' => $fillers(['Rawalpindi Grain Market', 'Capital Edible Oil', 'Islamabad Packaging']),
            ],
        ];
    }

    /**
     * @return list<array{created: bool, company_id: int, code: string, email: string, password: string|null, summary: array<string, int>}>
     */
    public function build(int $companies = 4, int $months = 4, int $salesPerMonth = 30, int $purchasesPerMonth = 10, ?CarbonImmutable $today = null): array
    {
        $profiles = self::profiles();
        $results = [];

        for ($index = 0; $index < min(max(1, $companies), count($profiles)); $index++) {
            $number = $index + 1;
            $profile = $profiles[$index] + [
                'code' => self::PREFIX.$number, 'admin_email' => "showcase{$number}-admin@demo.invalid", 'username' => "showcase{$number}_admin", 'admin_first' => 'Showcase',
                'company_email' => "info@showcase{$number}.demo.invalid", 'seed' => $number, 'months' => max(1, $months), 'sales_per_month' => max(1, $salesPerMonth),
                'purchases_per_month' => max(1, $purchasesPerMonth),
            ];

            $results[] = (new PilotDemoCompany)->buildShowcase($profile, $today);
        }

        return $results;
    }

    /**
     * Removes every showcase company (the purge a cancelled tenant gets). Only codes that start with DEMO-SHOW- are touched.
     */
    public function remove(): int
    {
        $removed = 0;

        foreach (Company::query()->where('code', 'like', self::PREFIX.'%')->where('code', 'not like', '%-REMOVED-%')->pluck('code') as $code) {
            if ((new PilotDemoCompany)->remove((string) $code)) {
                $removed++;
            }
        }

        return $removed;
    }
}
