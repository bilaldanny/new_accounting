<?php

namespace App\Services\Documents;

use Illuminate\Validation\ValidationException;

/**
 * The design of a printed document is a list of blocks (JSON): a header, a title, the parties, the lines, the totals, free text, terms,
 * notes, custom fields, a QR code, signatures and a footer. This class is the whitelist: a block type or property that is not listed
 * here is refused (type) or dropped (property), every text is stripped of markup and cut to length, and every number is clamped. The
 * renderer only ever reads what this class returned, and escapes it again on output.
 *
 * Branding is a small set of structured properties (colours, a font family from a list, sizes, border and table styles) - never
 * free CSS - and each value is validated against a pattern or a list, so a template cannot inject a style rule or a URL.
 */
final class DocumentBlocks
{
    public const MAX_BLOCKS = 40;

    public const DOC_TYPES = ['invoice', 'quotation', 'receipt', 'sell_return', 'purchase_order'];

    public const LANGUAGES = ['en', 'ur'];

    public const PAPERS = ['a4', 'a5', 'thermal80', 'thermal58'];

    public const LINE_COLUMNS = ['sr', 'sku', 'name', 'qty', 'unit', 'price', 'discount', 'tax', 'amount'];

    public const FONTS = ['sans' => 'Arial, Helvetica, sans-serif', 'serif' => 'Georgia, "Times New Roman", serif', 'mono' => '"Courier New", monospace', 'system' => 'system-ui, sans-serif'];

    /**
     * The codes a company can type into any free text of a design (a Text block, Terms, Footer, the title) to print a fact of the
     * document: `{invoice}` becomes the invoice number. Only these codes are replaced; anything else in braces stays as typed. The value
     * is plain text and is escaped on output, so a code can never bring markup in.
     *
     * @var array<string, array{0: string, 1: string}> code => [group, what it prints]
     */
    public const CODES = [
        'invoice' => ['Document', 'Document number'], 'date' => ['Document', 'Document date'], 'due_date' => ['Document', 'Due date'],
        'status' => ['Document', 'Status'], 'reference' => ['Document', 'Supplier / customer reference'], 'note' => ['Document', 'Note on the document'],
        'today' => ['Document', 'Date it is printed'], 'fbr_number' => ['Document', 'FBR invoice number (only when FBR issued one)'],
        'customer' => ['Party', 'Customer / supplier name'], 'customer_address' => ['Party', 'Their address'], 'customer_phone' => ['Party', 'Their phone'],
        'customer_email' => ['Party', 'Their email'], 'customer_ntn' => ['Party', 'Their NTN'], 'customer_strn' => ['Party', 'Their STRN'], 'ship_to' => ['Party', 'Shipping address'],
        'company' => ['Your company', 'Company name'], 'company_address' => ['Your company', 'Address'], 'company_phone' => ['Your company', 'Phone'],
        'company_email' => ['Your company', 'Email'], 'company_ntn' => ['Your company', 'NTN'], 'company_strn' => ['Your company', 'STRN'],
        'subtotal' => ['Amounts', 'Subtotal before tax'], 'discount' => ['Amounts', 'Discount'], 'tax' => ['Amounts', 'Tax'], 'shipping' => ['Amounts', 'Shipping'],
        'total' => ['Amounts', 'Grand total'], 'paid' => ['Amounts', 'Paid so far'], 'balance' => ['Amounts', 'Balance due'], 'previous_balance' => ['Amounts', 'Earlier balance of the customer'],
        'amount_in_words' => ['Amounts', 'Grand total in words'], 'items' => ['Amounts', 'Number of lines'],
    ];

    /**
     * Block type => [property => rule]. Rules: bool, text:<max>, int:<min>:<max>, enum:<a|b>, columns, labels.
     *
     * @var array<string, array<string, string>>
     */
    public const SCHEMA = [
        'header' => ['show_logo' => 'bool', 'show_address' => 'bool', 'show_contacts' => 'bool', 'show_tax_numbers' => 'bool', 'align' => 'enum:left|center|right'],
        'title' => ['text' => 'text:80', 'show_number' => 'bool', 'show_date' => 'bool', 'show_due_date' => 'bool', 'align' => 'enum:left|center|right'],
        'parties' => ['show_bill_to' => 'bool', 'show_ship_to' => 'bool', 'show_phone' => 'bool'],
        'lines' => ['columns' => 'columns', 'show_header' => 'bool'],
        'totals' => ['show_discount' => 'bool', 'show_tax' => 'bool', 'show_shipping' => 'bool', 'show_paid' => 'bool', 'show_balance' => 'bool', 'show_previous_balance' => 'bool'],
        'text' => ['text' => 'text:3000', 'align' => 'enum:left|center|right', 'bold' => 'bool', 'size' => 'enum:small|normal|large|xlarge'],
        'terms' => ['text' => 'text:2000'],
        'notes' => [],
        'custom_fields' => [],
        'qr' => ['content' => 'enum:link|invoice_no', 'size_px' => 'int:60:220'],
        'signature' => ['labels' => 'labels'],
        'footer' => ['text' => 'text:500', 'align' => 'enum:left|center|right'],
        'divider' => [],
        'spacer' => ['height_mm' => 'int:1:60'],
    ];

    /**
     * The design used when a company has not made one.
     *
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    public static function defaults(string $docType): array
    {
        $blocks = [
            ['type' => 'header', 'props' => ['show_logo' => true, 'show_address' => true, 'show_contacts' => true, 'show_tax_numbers' => true, 'align' => 'left']],
            ['type' => 'title', 'props' => ['show_number' => true, 'show_date' => true, 'show_due_date' => $docType === 'invoice', 'align' => 'right']],
            ['type' => 'parties', 'props' => ['show_bill_to' => true, 'show_ship_to' => false, 'show_phone' => true]],
            ['type' => 'lines', 'props' => ['columns' => ['sr', 'name', 'qty', 'price', 'discount', 'tax', 'amount'], 'show_header' => true]],
            ['type' => 'totals', 'props' => ['show_discount' => true, 'show_tax' => true, 'show_shipping' => true, 'show_paid' => $docType !== 'quotation', 'show_balance' => $docType !== 'quotation', 'show_previous_balance' => false]],
            ['type' => 'custom_fields', 'props' => []],
            ['type' => 'notes', 'props' => []],
            ['type' => 'terms', 'props' => []],
            ['type' => 'signature', 'props' => ['labels' => ['Prepared by', 'Received by']]],
            ['type' => 'footer', 'props' => ['align' => 'center']],
        ];

        return $blocks;
    }

    /**
     * @return array<string, string|int|bool>
     */
    public static function defaultBranding(): array
    {
        return ['primary_color' => '#1f2937', 'accent_color' => '#e5e7eb', 'text_color' => '#111827', 'font_family' => 'sans', 'font_size_pt' => 10, 'logo_height_px' => 60, 'border_style' => 'none', 'table_style' => 'grid'];
    }

    /**
     * Validates a block list and returns it normalised.
     *
     * @param  mixed  $blocks
     * @return list<array{type: string, props: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public static function normalise($blocks): array
    {
        if (! is_array($blocks) || $blocks === []) {
            throw ValidationException::withMessages(['blocks' => ['Add at least one block.']]);
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['blocks' => ['A document can have at most '.self::MAX_BLOCKS.' blocks.']]);
        }

        $result = [];

        foreach (array_values($blocks) as $index => $block) {
            $type = is_array($block) ? (string) ($block['type'] ?? '') : '';

            if (! array_key_exists($type, self::SCHEMA)) {
                throw ValidationException::withMessages(["blocks.{$index}.type" => ['Block type ['.($type === '' ? 'empty' : mb_substr($type, 0, 40)).'] is not available.']]);
            }

            $given = is_array($block['props'] ?? null) ? $block['props'] : [];
            $props = [];

            foreach (self::SCHEMA[$type] as $name => $rule) {
                if (array_key_exists($name, $given)) {
                    $value = self::clean($rule, $given[$name]);

                    if ($value !== null) {
                        $props[$name] = $value;
                    }
                }
            }

            $result[] = ['type' => $type, 'props' => $props];
        }

        return $result;
    }

    /**
     * Validates branding and returns only the known properties with safe values.
     *
     * @param  mixed  $branding
     * @return array<string, string|int>
     *
     * @throws ValidationException
     */
    public static function normaliseBranding($branding): array
    {
        $given = is_array($branding) ? $branding : [];
        $result = self::defaultBranding();

        foreach (['primary_color', 'accent_color', 'text_color'] as $key) {
            if (isset($given[$key])) {
                if (! is_string($given[$key]) || preg_match('/^#[0-9a-fA-F]{6}$/', $given[$key]) !== 1) {
                    throw ValidationException::withMessages(["branding.{$key}" => ['Use a colour such as #1f2937.']]);
                }

                $result[$key] = strtolower($given[$key]);
            }
        }

        if (isset($given['font_family'])) {
            if (! array_key_exists((string) $given['font_family'], self::FONTS)) {
                throw ValidationException::withMessages(['branding.font_family' => ['Choose one of the listed fonts.']]);
            }

            $result['font_family'] = (string) $given['font_family'];
        }

        foreach (['font_size_pt' => [7, 16], 'logo_height_px' => [20, 140]] as $key => [$min, $max]) {
            if (isset($given[$key])) {
                if (! is_numeric($given[$key]) || (int) $given[$key] < $min || (int) $given[$key] > $max) {
                    throw ValidationException::withMessages(["branding.{$key}" => ["Enter a number from {$min} to {$max}."]]);
                }

                $result[$key] = (int) $given[$key];
            }
        }

        foreach (['border_style' => ['none', 'thin', 'box'], 'table_style' => ['plain', 'striped', 'grid']] as $key => $allowed) {
            if (isset($given[$key])) {
                if (! in_array($given[$key], $allowed, true)) {
                    throw ValidationException::withMessages(["branding.{$key}" => ['Choose one of: '.implode(', ', $allowed).'.']]);
                }

                $result[$key] = (string) $given[$key];
            }
        }

        return $result;
    }

    /**
     * Strips markup and control characters from a text and cuts it to a length.
     */
    public static function text(mixed $value, int $max): string
    {
        $text = strip_tags((string) (is_scalar($value) ? $value : ''));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return mb_substr(trim($text), 0, $max);
    }

    private static function clean(string $rule, mixed $value): mixed
    {
        [$kind, $a, $b] = array_pad(explode(':', $rule), 3, null);

        return match ($kind) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'text' => is_scalar($value) ? self::text($value, (int) $a) : null,
            'int' => is_numeric($value) ? max((int) $a, min((int) $b, (int) $value)) : null,
            'enum' => in_array($value, explode('|', (string) $a), true) ? $value : null,
            'columns' => is_array($value) ? array_values(array_unique(array_filter($value, fn ($c): bool => in_array($c, self::LINE_COLUMNS, true)))) ?: ['name', 'qty', 'amount'] : null,
            'labels' => is_array($value) ? array_slice(array_values(array_filter(array_map(fn ($label): string => self::text($label, 40), $value), fn (string $label): bool => $label !== '')), 0, 3) : null,
            default => null,
        };
    }
}
