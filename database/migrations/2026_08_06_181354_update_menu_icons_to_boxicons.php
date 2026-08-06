<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Boxicons Vue PascalCase icon names for menus.icon.
     *
     * @var array<int, string>
     */
    private array $iconsByMenuId = [
        2 => 'Menu',
        17 => 'Building',
        22 => 'GitBranch',
        27 => 'Group',
        31 => 'Shield',
        36 => 'User',
        26 => 'UserCircle',
        40 => 'Cog',
        42 => 'Percentage',
        46 => 'Calendar',
        48 => 'Receipt',
        49 => 'PieChart',
        53 => 'Badge',
        54 => 'Truck',
        58 => 'User',
        62 => 'Bank',
        66 => 'Package',
        67 => 'Ruler',
        71 => 'Grid',
        75 => 'Medal',
        79 => 'ShieldQuarter',
        83 => 'Layers',
        87 => 'Tag',
        91 => 'Cube',
        95 => 'Archive',
        99 => 'Cart',
        100 => 'Cart',
        105 => 'CheckCircle',
        106 => 'CheckCircle',
        107 => 'Note',
        116 => 'Store',
        117 => 'Store',
        122 => 'CheckCircle',
        123 => 'Note',
        128 => 'Cart',
        133 => 'File',
        134 => 'File',
        135 => 'Package',
        136 => 'Store',
        145 => 'CheckCircle',
        146 => 'CheckCircle',
        147 => 'File',
        148 => 'CheckCircle',
    ];

    public function up(): void
    {
        foreach ($this->iconsByMenuId as $menuId => $icon) {
            DB::table('menus')
                ->where('id', $menuId)
                ->update(['icon' => $icon]);
        }
    }

    public function down(): void
    {
        $legacyIcons = [
            2 => 'fal fa-bars',
            17 => 'fal fa-building',
            22 => 'bx bx-buildings',
            27 => 'bx bx-buildings',
            31 => 'bx bx-buildings',
            36 => 'bx bx-user',
            26 => 'fal fa-user',
            40 => 'fal fa-cog',
            42 => 'bx bx-buildings',
            46 => 'bx bx-buildings',
            48 => 'fal fa-file-invoice',
            49 => 'bx bx-buildings',
            53 => 'fal fa-id-badge',
            54 => 'bx bx-buildings',
            58 => 'bx bx-buildings',
            62 => 'bx bx-buildings',
            66 => 'fab fa-product-hunt',
            67 => 'bx bx-buildings',
            71 => 'bx bx-buildings',
            75 => 'bx bx-buildings',
            79 => 'bx bx-buildings',
            83 => 'bx bx-buildings',
            87 => 'bx bx-buildings',
            91 => 'bx bx-buildings',
            95 => 'bx bx-data',
            99 => 'fal fa-tags',
            100 => 'bx bx-buildings',
            105 => 'fal fa-thumbs-up',
            106 => 'bx bx-buildings',
            107 => 'bx bx-buildings',
            116 => 'far fa-badge-dollar',
            117 => 'bx bx-buildings',
            122 => 'bx bx-buildings',
            123 => 'bx bx-buildings',
            128 => 'bx bx-buildings',
            133 => 'bx bx-buildings',
            134 => 'bx bx-buildings',
            135 => '',
            136 => '',
            145 => 'bx bx-buildings',
            146 => 'bx bx-buildings',
            147 => 'bx bx-buildings',
            148 => 'bx bx-buildings',
        ];

        foreach ($legacyIcons as $menuId => $icon) {
            DB::table('menus')
                ->where('id', $menuId)
                ->update(['icon' => $icon]);
        }
    }
};
