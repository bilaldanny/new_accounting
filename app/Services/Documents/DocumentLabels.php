<?php

namespace App\Services\Documents;

/**
 * The words printed on a document, in English and Urdu. A language that is not here prints in English; Urdu documents are laid out
 * right to left. Amounts and dates are printed with Western digits in both languages so they match the ledgers and reports.
 */
final class DocumentLabels
{
    /**
     * @var array<string, array<string, string>>
     */
    private const WORDS = [
        'en' => [
            'invoice' => 'Invoice', 'quotation' => 'Quotation', 'receipt' => 'Receipt', 'sell_return' => 'Sales Return', 'purchase_order' => 'Purchase Order',
            'number' => 'No', 'date' => 'Date', 'due_date' => 'Due date', 'bill_to' => 'Bill to', 'ship_to' => 'Ship to', 'phone' => 'Phone', 'email' => 'Email',
            'address' => 'Address', 'ntn' => 'NTN', 'strn' => 'STRN', 'sr' => '#', 'sku' => 'SKU', 'item' => 'Item', 'qty' => 'Qty', 'unit' => 'Unit',
            'price' => 'Price', 'discount' => 'Discount', 'tax' => 'Tax', 'amount' => 'Amount', 'subtotal' => 'Subtotal', 'shipping' => 'Shipping',
            'total' => 'Total', 'paid' => 'Paid', 'balance' => 'Balance due', 'previous_balance' => 'Previous balance', 'terms' => 'Terms and conditions',
            'notes' => 'Notes', 'prepared_by' => 'Prepared by', 'received_by' => 'Received by', 'thank_you' => 'Thank you for your business', 'fbr_number' => 'FBR invoice number',
            'supplier' => 'Supplier', 'customer' => 'Customer',
        ],
        'ur' => [
            'invoice' => 'انوائس', 'quotation' => 'کوٹیشن', 'receipt' => 'رسید', 'sell_return' => 'فروخت کی واپسی', 'purchase_order' => 'خریداری کا آرڈر',
            'number' => 'نمبر', 'date' => 'تاریخ', 'due_date' => 'آخری تاریخ', 'bill_to' => 'بل برائے', 'ship_to' => 'ترسیل کا پتہ', 'phone' => 'فون', 'email' => 'ای میل',
            'address' => 'پتہ', 'ntn' => 'این ٹی این', 'strn' => 'ایس ٹی آر این', 'sr' => 'نمبر', 'sku' => 'کوڈ', 'item' => 'آئٹم', 'qty' => 'مقدار', 'unit' => 'اکائی',
            'price' => 'قیمت', 'discount' => 'رعایت', 'tax' => 'ٹیکس', 'amount' => 'رقم', 'subtotal' => 'ذیلی کل', 'shipping' => 'ترسیل کے اخراجات',
            'total' => 'کل', 'paid' => 'ادا شدہ', 'balance' => 'بقایا', 'previous_balance' => 'سابقہ بقایا', 'terms' => 'شرائط و ضوابط',
            'notes' => 'نوٹ', 'prepared_by' => 'تیار کنندہ', 'received_by' => 'وصول کنندہ', 'thank_you' => 'آپ کے کاروبار کا شکریہ', 'fbr_number' => 'ایف بی آر انوائس نمبر',
            'supplier' => 'سپلائر', 'customer' => 'گاہک',
        ],
    ];

    public static function word(string $language, string $key): string
    {
        return self::WORDS[$language][$key] ?? self::WORDS['en'][$key] ?? $key;
    }

    public static function direction(string $language): string
    {
        return $language === 'ur' ? 'rtl' : 'ltr';
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::WORDS['en']);
    }
}
