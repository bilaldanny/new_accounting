<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company settings of the documents the app prints: barcode labels, invoices, receipts. One row per
 * company and group; `GROUPS` is the single definition of what each group holds (label, the menu page it
 * lives on, and for every setting its type, default, validation rules and form hints), so the API, the
 * validation and the settings page are all driven from it.
 *
 * These are stored settings only: apart from the Print Label defaults, no printing or numbering code
 * reads them yet.
 */
class DocumentSetting extends Model
{
    /**
     * @var array<string, array{label: string, path: string, fields: array<string, array<string, mixed>>}>
     */
    public const GROUPS = [
        'barcode' => [
            'label' => 'Barcode Settings',
            'path' => '/barcode/settings',
            'fields' => [
                'barcode_type' => ['label' => 'Barcode format', 'type' => 'select', 'default' => 'CODE128', 'rules' => 'required|in:CODE128,EAN13,UPC,CODE39', 'options' => ['CODE128' => 'CODE128', 'EAN13' => 'EAN-13', 'UPC' => 'UPC', 'CODE39' => 'CODE39']],
                'layout' => ['label' => 'Sheet layout', 'type' => 'select', 'default' => 'wide', 'rules' => 'required|in:wide,compact', 'options' => ['wide' => 'Wide stickers', 'compact' => 'Compact stickers']],
                'label_width_mm' => ['label' => 'Label width (mm)', 'type' => 'number', 'default' => 50, 'rules' => 'required|integer|between:10,300', 'help' => 'Saved for the label designer; the Print Label sheets do not use it yet.'],
                'label_height_mm' => ['label' => 'Label height (mm)', 'type' => 'number', 'default' => 25, 'rules' => 'required|integer|between:10,300'],
                'show_price' => ['label' => 'Price display', 'type' => 'select', 'default' => 'exclusive', 'rules' => 'required|in:exclusive,inclusive', 'options' => ['exclusive' => 'Exclusive of tax', 'inclusive' => 'Inclusive of tax']],
                'show_business_name' => ['label' => 'Show business name', 'type' => 'switch', 'default' => true, 'rules' => 'required|boolean'],
                'show_product_name' => ['label' => 'Show product name', 'type' => 'switch', 'default' => true, 'rules' => 'required|boolean'],
                'show_variation' => ['label' => 'Show variation', 'type' => 'switch', 'default' => true, 'rules' => 'required|boolean'],
                'show_product_price' => ['label' => 'Show price', 'type' => 'switch', 'default' => true, 'rules' => 'required|boolean'],
            ],
        ],
        'invoice' => [
            'label' => 'Invoice Settings',
            'path' => '/invoice/settings',
            'fields' => [
                'number_digits' => ['label' => 'Invoice number digits', 'type' => 'number', 'default' => 5, 'rules' => 'required|integer|between:3,10', 'help' => 'Saved only: invoice numbers are still made with the prefixes in Company Settings.'],
                'number_separator' => ['label' => 'Number separator', 'type' => 'select', 'default' => '-', 'rules' => 'present|in:-,/,', 'options' => ['-' => 'Dash (INV-00001)', '/' => 'Slash (INV/00001)', '' => 'None (INV00001)']],
                'show_terms_and_conditions' => ['label' => 'Print terms and conditions', 'type' => 'switch', 'default' => true, 'rules' => 'required|boolean'],
                'terms_and_conditions' => ['label' => 'Terms and conditions', 'type' => 'textarea', 'default' => '', 'rules' => 'nullable|string|max:2000'],
                'show_footer_note' => ['label' => 'Print footer note', 'type' => 'switch', 'default' => true, 'rules' => 'required|boolean'],
                'footer_note' => ['label' => 'Footer note', 'type' => 'text', 'default' => '', 'rules' => 'nullable|string|max:500'],
            ],
        ],
        'receipt' => [
            'label' => 'Receipt Printer Settings',
            'path' => '/receipt/settings',
            'fields' => [
                'printer_type' => ['label' => 'Printer type', 'type' => 'select', 'default' => 'thermal', 'rules' => 'required|in:thermal,a4,a5,dot_matrix', 'options' => ['thermal' => 'Thermal receipt printer', 'a4' => 'A4 sheet', 'a5' => 'A5 sheet', 'dot_matrix' => 'Dot matrix']],
                'paper_width_mm' => ['label' => 'Paper width (mm)', 'type' => 'select', 'default' => 80, 'rules' => 'required|integer|in:58,80', 'options' => [58 => '58 mm', 80 => '80 mm'], 'help' => 'Used by thermal printers.'],
                'copies' => ['label' => 'Copies', 'type' => 'number', 'default' => 1, 'rules' => 'required|integer|between:1,5'],
                'show_logo' => ['label' => 'Print the company logo', 'type' => 'switch', 'default' => true, 'rules' => 'required|boolean'],
                'show_barcode' => ['label' => 'Print the invoice barcode', 'type' => 'switch', 'default' => false, 'rules' => 'required|boolean'],
                'auto_print' => ['label' => 'Print automatically after a sale', 'type' => 'switch', 'default' => false, 'rules' => 'required|boolean'],
                'header_text' => ['label' => 'Header text', 'type' => 'text', 'default' => '', 'rules' => 'nullable|string|max:200'],
                'footer_text' => ['label' => 'Footer text', 'type' => 'text', 'default' => 'Thank you for your business', 'rules' => 'nullable|string|max:300'],
            ],
        ],
        'checkout' => [
            'label' => 'Checkout Extras',
            'path' => '/checkout/settings',
            'fields' => [
                'discount_codes_enabled' => ['label' => 'Discount codes at checkout', 'type' => 'switch', 'default' => false, 'rules' => 'required|boolean', 'help' => 'Shows the Apply Discount Code field on the sell form and the POS.'],
                'gift_cards_enabled' => ['label' => 'Gift cards as payment', 'type' => 'switch', 'default' => false, 'rules' => 'required|boolean', 'help' => 'Shows the Pay with Gift Card field on the sell form and the POS.'],
            ],
        ],
    ];

    protected $fillable = [
        'company_id',
        'group',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function isGroup(string $group): bool
    {
        return array_key_exists($group, self::GROUPS);
    }

    /**
     * The validation rules of a group, every field optional in a request (a missing one keeps its value).
     *
     * @return array<string, string>
     */
    public static function rulesFor(string $group): array
    {
        return array_map(fn (array $field): string => 'sometimes|'.$field['rules'], self::GROUPS[$group]['fields']);
    }

    /**
     * The values of a group for a company: what it saved, over the defaults. Keys the group no longer
     * defines are dropped and a saved value of the wrong type falls back to the default.
     *
     * @return array<string, bool|int|string|null>
     */
    public static function valuesFor(int $companyId, string $group): array
    {
        $saved = self::query()->where('company_id', $companyId)->where('group', $group)->value('settings');
        $saved = is_string($saved) ? (json_decode($saved, true) ?: []) : ($saved ?? []);
        $values = [];

        foreach (self::GROUPS[$group]['fields'] as $key => $field) {
            $values[$key] = array_key_exists($key, $saved) ? self::cast($field, $saved[$key]) : $field['default'];
        }

        return $values;
    }

    /**
     * Saves the given keys over the current values (a missing key keeps its value) and returns them all.
     * Unknown keys are ignored.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, bool|int|string|null>
     */
    public static function saveFor(int $companyId, string $group, array $input): array
    {
        $values = self::valuesFor($companyId, $group);

        foreach (self::GROUPS[$group]['fields'] as $key => $field) {
            if (array_key_exists($key, $input)) {
                $values[$key] = self::cast($field, $input[$key]);
            }
        }

        self::query()->updateOrCreate(['company_id' => $companyId, 'group' => $group], ['settings' => $values]);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function cast(array $field, mixed $value): bool|int|string|null
    {
        return match ($field['type']) {
            'switch' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? (int) $value : $field['default'],
            'select' => is_int($field['default']) ? (is_numeric($value) ? (int) $value : $field['default']) : (string) ($value ?? ''),
            default => trim((string) ($value ?? '')),
        };
    }

    /**
     * The form description the settings page draws from: key, label, type, options and help.
     *
     * @return list<array<string, mixed>>
     */
    public static function fieldsFor(string $group): array
    {
        $fields = [];

        foreach (self::GROUPS[$group]['fields'] as $key => $field) {
            $fields[] = ['key' => $key] + array_intersect_key($field, array_flip(['label', 'type', 'options', 'help']));
        }

        return $fields;
    }
}
