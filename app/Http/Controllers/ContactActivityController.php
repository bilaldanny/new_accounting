<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Transaction;
use App\Services\StockMovements;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The tabs of a customer or supplier page that are not the ledger: their purchases or sales, the payments, the stock they supplied or
 * took, and the files attached to those documents. Every read starts from Contact::findVisibleContact, the rule the ledger screen
 * uses, so a user only reaches a party of their own company and branch.
 */
class ContactActivityController extends Controller
{
    private const LIMIT = 200;

    /**
     * The party's purchases (supplier) or sales (customer), newest first, with what was paid and what is still due.
     */
    public function orders(int $id): JsonResponse
    {
        $contact = $this->contact($id);
        [$types, $view] = $this->documentTypes($contact);

        $rows = DB::table('transactions as t')
            ->where('t.contact_id', $contact->id)->whereIn('t.type', $types)->whereNull('t.deleted_at')
            ->orderByDesc('t.transaction_date')->orderByDesc('t.id')->limit(self::LIMIT)
            ->get(['t.id', 't.invoice_no', 't.type', 't.transaction_date', 't.status', 't.payment_status', 't.final_amount', 't.attachment']);

        $paid = DB::table('payments')->whereIn('transaction_id', $rows->pluck('id'))->where('is_return', false)
            ->selectRaw('transaction_id, sum(amount) as paid')->groupBy('transaction_id')->pluck('paid', 'transaction_id');

        $data = $rows->map(function (object $row) use ($paid, $view): array {
            $isReturn = in_array($row->type, [Transaction::TYPE_PURCHASE_RETURN, Transaction::TYPE_SELL_RETURN], true);
            $total = round((float) $row->final_amount, 2);
            $settled = round((float) ($paid[$row->id] ?? 0), 2);

            return [
                'id' => (int) $row->id, 'invoice_no' => (string) $row->invoice_no, 'type' => (string) $row->type, 'is_return' => $isReturn,
                'date' => substr((string) $row->transaction_date, 0, 10), 'status' => (string) $row->status, 'payment_status' => (string) $row->payment_status,
                'total' => $total, 'paid' => $settled, 'due' => $isReturn ? 0.0 : round(max(0.0, $total - $settled), 2),
                'has_attachment' => filled($row->attachment), 'view_url' => $isReturn ? null : str_replace('{id}', (string) $row->id, $view),
            ];
        })->values();

        return response()->json(['data' => $data, 'summary' => [
            'count' => $data->count(), 'total' => round((float) $data->where('is_return', false)->sum('total'), 2), 'paid' => round((float) $data->where('is_return', false)->sum('paid'), 2),
            'due' => round((float) $data->sum('due'), 2),
        ], 'limit' => self::LIMIT]);
    }

    /**
     * The payments recorded for the party, newest first.
     */
    public function payments(int $id): JsonResponse
    {
        $contact = $this->contact($id);

        $rows = DB::table('payments as p')
            ->leftJoin('transactions as t', 't.id', '=', 'p.transaction_id')
            ->leftJoin('chart_of_accounts as a', 'a.id', '=', 'p.payment_account')
            ->where(fn ($q) => $q->where('p.contact_id', $contact->id)->orWhere('t.contact_id', $contact->id))
            ->orderByDesc('p.paid_on')->orderByDesc('p.id')->limit(self::LIMIT)
            ->get(['p.id', 'p.amount', 'p.method', 'p.paid_on', 'p.is_return', 'p.note', 'a.name as account', 't.invoice_no']);

        $data = $rows->map(fn (object $row): array => [
            'id' => (int) $row->id, 'date' => substr((string) $row->paid_on, 0, 10), 'amount' => round((float) $row->amount, 2), 'method' => (string) $row->method,
            'account' => (string) ($row->account ?? ''), 'invoice_no' => (string) ($row->invoice_no ?? ''), 'note' => (string) ($row->note ?? ''), 'is_return' => (bool) $row->is_return,
        ])->values();

        return response()->json(['data' => $data, 'summary' => [
            'count' => $data->count(), 'total' => round((float) $data->where('is_return', false)->sum('amount') - (float) $data->where('is_return', true)->sum('amount'), 2),
        ], 'limit' => self::LIMIT]);
    }

    /**
     * The products bought from the supplier (or sold to the customer): quantities, value, the last rate and what is on hand now.
     */
    public function stock(int $id): JsonResponse
    {
        $contact = $this->contact($id);
        $isCustomer = $contact->user_type === 'customer';
        $lines = $isCustomer ? 'sell_lines' : 'purchase_lines';
        $type = $isCustomer ? Transaction::TYPE_SELL : Transaction::TYPE_PURCHASE;
        $amount = $isCustomer ? 'sum(l.subtotal)' : 'sum(l.quantity * l.purchase_rate * coalesce(nullif(l.packing_qty, 0), 1) * (1 - coalesce(l.discount_percent, 0) / 100))';
        $rate = $isCustomer ? 'l.unit_price' : 'l.purchase_rate';

        $rows = DB::table("{$lines} as l")
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('t.contact_id', $contact->id)->where('t.type', $type)->whereNull('t.deleted_at')->whereNotIn('t.status', ['draft', 'quotation'])
            ->groupBy('l.product_id', 'l.variation_id', 'p.name', 'p.sku')
            ->selectRaw("l.product_id, l.variation_id, p.name, p.sku, sum(l.quantity) as quantity, sum(coalesce(l.quantity_returned, 0)) as returned, {$amount} as amount, max(t.transaction_date) as last_date, count(distinct t.id) as documents")
            ->orderByDesc('amount')->limit(self::LIMIT)->get();

        $data = $rows->map(function (object $row) use ($lines, $contact, $type, $rate): array {
            $last = DB::table("{$lines} as l")->join('transactions as t', 't.id', '=', 'l.transaction_id')
                ->where('t.contact_id', $contact->id)->where('t.type', $type)->where('l.product_id', $row->product_id)->where('l.variation_id', $row->variation_id)
                ->orderByDesc('t.transaction_date')->orderByDesc('l.id')->value(DB::raw($rate));

            return [
                'product_id' => (int) $row->product_id, 'name' => (string) $row->name, 'sku' => (string) $row->sku, 'quantity' => round((float) $row->quantity, 4),
                'returned' => round((float) $row->returned, 4), 'amount' => round((float) $row->amount, 2), 'last_rate' => round((float) $last, 2),
                'last_date' => substr((string) $row->last_date, 0, 10), 'documents' => (int) $row->documents,
                'on_hand' => StockMovements::baseStock((int) $row->product_id, (int) $row->variation_id, null),
            ];
        })->values();

        return response()->json(['data' => $data, 'summary' => ['products' => $data->count(), 'amount' => round((float) $data->sum('amount'), 2)], 'kind' => $isCustomer ? 'sold' : 'bought', 'limit' => self::LIMIT]);
    }

    /**
     * The files attached to the party's purchases and sales (and their return notes): the invoice or bill that came with each document.
     */
    public function attachments(int $id): JsonResponse
    {
        $contact = $this->contact($id);

        $rows = DB::table('transactions as t')
            ->where('t.contact_id', $contact->id)->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->where(fn ($a) => $a->whereNotNull('t.attachment')->where('t.attachment', '!=', ''))->orWhere(fn ($b) => $b->whereNotNull('t.billty_image')->where('t.billty_image', '!=', '')))
            ->orderByDesc('t.transaction_date')->orderByDesc('t.id')->limit(self::LIMIT)
            ->get(['t.id', 't.invoice_no', 't.type', 't.transaction_date', 't.final_amount', 't.attachment', 't.billty_image']);

        $labels = [
            Transaction::TYPE_PURCHASE => 'Purchase', Transaction::TYPE_PURCHASE_RETURN => 'Purchase return', Transaction::TYPE_SELL => 'Sale', Transaction::TYPE_SELL_RETURN => 'Sale return',
            Transaction::TYPE_RECEIVING_NOTE => 'Receiving note', Transaction::TYPE_ISSUE_NOTE => 'Issue note',
        ];
        $data = [];

        foreach ($rows as $row) {
            foreach (['attachment' => 'Attachment', 'billty_image' => 'Delivery / billty'] as $column => $what) {
                $path = (string) ($row->{$column} ?? '');

                if ($path === '') {
                    continue;
                }

                $data[] = [
                    'key' => $row->id.'-'.$column, 'transaction_id' => (int) $row->id, 'invoice_no' => (string) $row->invoice_no, 'document' => $labels[$row->type] ?? 'Document',
                    'date' => substr((string) $row->transaction_date, 0, 10), 'amount' => round((float) $row->final_amount, 2), 'what' => $what,
                    'file_name' => basename(parse_url($path, PHP_URL_PATH) ?: $path), 'url' => Transaction::imageUrl($path),
                    'is_image' => preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $path) === 1,
                ];
            }
        }

        return response()->json(['data' => $data, 'limit' => self::LIMIT]);
    }

    private function contact(int $id): Contact
    {
        abort_unless(hasAnyMenuPermission('/customer', '/supplier', '/report/ledger', '/report/account-ledger'), 403, 'You do not have permission to perform this action.');

        return Contact::findVisibleContact($id) ?? abort(404);
    }

    /**
     * @return array{0: list<string>, 1: string} the document types of the party and the page that opens one
     */
    private function documentTypes(Contact $contact): array
    {
        return $contact->user_type === 'customer'
            ? [[Transaction::TYPE_SELL, Transaction::TYPE_SELL_RETURN], '/sell/{id}/view']
            : [[Transaction::TYPE_PURCHASE, Transaction::TYPE_PURCHASE_RETURN], '/purchase/{id}/view'];
    }
}
