<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Rental\RentalService;
use App\Services\Rental\RentalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Rental: items, the booking calendar, pickup with the deposit and the return with inspection, late charge and damage charge.
 * Reading needs `/rental`; items and settings `/rental/manage`; bookings, pickup, return and cancel `/rental/book`. Company and branch
 * come from the signed-in user and every id is looked up inside the company.
 */
class RentalController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly RentalService $rentals, private readonly RentalSettings $settings) {}

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rental');

        return response()->json(['data' => $this->settings->all($this->scopeCompanyId($request)), 'account_slots' => RentalSettings::ACCOUNT_SLOTS]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rental/manage');

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->settings->save($this->scopeCompanyId($request), $request->except(['company_id']))]);
    }

    public function items(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rental');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => DB::table('rental_items as r')->join('products as p', 'p.id', '=', 'r.product_id')->where('r.company_id', $companyId)->orderBy('p.name')
            ->get(['r.id', 'r.product_id', 'r.variation_id', 'p.name', 'r.units', 'r.daily_rate', 'r.deposit_amount', 'r.late_fee_per_day', 'r.is_active', 'r.notes'])]);
    }

    public function saveItem(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/rental/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'product_id' => 'required|integer', 'variation_id' => 'nullable|integer', 'units' => 'required|integer|min:1|max:100000', 'daily_rate' => 'required|numeric|min:0', 'deposit_amount' => 'nullable|numeric|min:0',
            'late_fee_per_day' => 'nullable|numeric|min:0', 'is_active' => 'nullable|boolean', 'notes' => 'nullable|string|max:200', 'company_id' => 'nullable|integer',
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $this->rentals->saveItem($companyId, null, $id, $data)]], 201);
    }

    public function calendar(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rental');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = (string) $request->query('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->query('to', now()->endOfMonth()->toDateString());
        $items = DB::table('rental_items as r')->join('products as p', 'p.id', '=', 'r.product_id')->where('r.company_id', $companyId)->orderBy('p.name')->get(['r.id', 'p.name', 'r.units']);
        $bookings = DB::table('rental_bookings as b')->leftJoin('contacts as c', 'c.id', '=', 'b.contact_id')->where('b.company_id', $companyId)->whereIn('b.status', ['reserved', 'out'])
            ->whereDate('b.start_date', '<=', $to)->where(fn ($q) => $q->whereDate('b.end_date', '>=', $from)->orWhere('b.status', 'out'))
            ->get(['b.id', 'b.rental_item_id', 'b.booking_no', 'b.start_date', 'b.end_date', 'b.quantity', 'b.status', 'c.business_name as customer'])->groupBy('rental_item_id');

        return response()->json(['data' => $items->map(fn (object $item): array => [
            'id' => (int) $item->id, 'name' => $item->name, 'units' => (int) $item->units, 'free_in_range' => $this->rentals->available($companyId, (int) $item->id, $from, $to),
            'bookings' => ($bookings[$item->id] ?? collect())->map(fn (object $b): array => (array) $b + ['overdue' => $b->status === 'out' && substr((string) $b->end_date, 0, 10) < now()->toDateString()])->values()->all(),
        ])->all(), 'from' => $from, 'to' => $to]);
    }

    public function availability(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rental');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['item_id' => 'required|integer', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);

        return response()->json(['data' => ['free' => $this->rentals->available($companyId, (int) $data['item_id'], $data['from'], $data['to'])]]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rental');
        $companyId = $this->scopeCompanyId($request);
        $status = (string) $request->query('status', '');

        return response()->json(['data' => DB::table('rental_bookings as b')->join('rental_items as r', 'r.id', '=', 'b.rental_item_id')->join('products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'b.contact_id')->where('b.company_id', $companyId)
            ->when($status !== '', fn ($q) => $q->where('b.status', $status))->orderByDesc('b.id')->limit(500)
            ->get(['b.id', 'b.booking_no', 'p.name as item', 'c.business_name as customer', 'b.quantity', 'b.start_date', 'b.end_date', 'b.status', 'b.rent_amount', 'b.deposit_amount', 'b.deposit_received',
                'b.late_days', 'b.late_charge', 'b.damage_charge', 'b.deposit_refund', 'b.amount_due', 'b.condition'])]);
    }

    public function showBooking(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/rental');

        return response()->json(['data' => (array) $this->rentals->find($this->scopeCompanyId($request), $id)]);
    }

    public function book(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rental/book');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'rental_item_id' => 'required|integer', 'contact_id' => 'required|integer', 'quantity' => 'required|integer|min:1', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d',
            'branch_id' => 'nullable|integer', 'company_id' => 'nullable|integer',
        ]);
        $id = $this->rentals->book($companyId, $this->scopeBranchId($request, $companyId), (int) $data['rental_item_id'], (int) $data['contact_id'], (int) $data['quantity'], $data['start_date'], $data['end_date'], Auth::id());

        return response()->json(['message' => 'Booked', 'data' => ['id' => $id]], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/rental/book');
        $this->rentals->cancel($this->scopeCompanyId($request), $id);

        return response()->json(['message' => 'Booking cancelled']);
    }

    public function pickup(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/rental/book');
        $data = $request->validate(['deposit' => 'nullable|numeric|min:0', 'payment_account_id' => 'nullable|integer', 'date' => 'nullable|date_format:Y-m-d', 'company_id' => 'nullable|integer']);
        $this->rentals->pickup($this->scopeCompanyId($request), $id, isset($data['deposit']) ? (float) $data['deposit'] : null, isset($data['payment_account_id']) ? (int) $data['payment_account_id'] : null, $data['date'] ?? now()->toDateString());

        return response()->json(['message' => 'Picked up']);
    }

    public function return(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/rental/book');
        $data = $request->validate([
            'returned_on' => 'nullable|date_format:Y-m-d', 'condition' => 'required|in:good,minor_damage,major_damage', 'damage_charge' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:500',
            'refund_account_id' => 'nullable|integer', 'company_id' => 'nullable|integer',
        ]);
        $result = $this->rentals->receiveBack(
            $this->scopeCompanyId($request), $id, $data['returned_on'] ?? now()->toDateString(), $data['condition'], (float) ($data['damage_charge'] ?? 0), $data['notes'] ?? null,
            isset($data['refund_account_id']) ? (int) $data['refund_account_id'] : null,
        );

        return response()->json(['message' => 'Returned', 'data' => $result]);
    }
}
