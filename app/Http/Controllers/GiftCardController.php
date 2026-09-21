<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\GiftCard;
use App\Models\GiftCardOrphanRefund;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GiftCardController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * What the redeem / top-up / lookup endpoints tell the caller for each rejection reason.
     *
     * @var array<string, string>
     */
    private const REJECTIONS = [
        'not_found' => 'This gift card code does not exist.',
        'inactive' => 'This gift card is switched off.',
        'expired' => 'This gift card has expired.',
        'insufficient_balance' => 'The gift card balance is less than the amount.',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function giftCardFormRules(Request $request, ?int $ignoreId = null): array
    {
        $isCreate = $ignoreId === null;
        $companyId = $isCreate ? GiftCard::scopedCompanyId($request) : (GiftCard::withTrashed()->whereKey($ignoreId)->value('company_id') ?: null);

        $rules = [
            'company_id' => $isCreate && Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'expires_at' => $isCreate ? 'nullable|date_format:Y-m-d|after_or_equal:today' : 'nullable|date_format:Y-m-d',
            'note' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ];

        if ($isCreate) {
            $rules['code'] = ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', $this->uniqueCodeRule($companyId)];
            $rules['initial_value'] = 'required|numeric|decimal:0,2|gt:0|max:9999999.99';
        }

        return $rules;
    }

    /**
     * A code is unique among a company's cards, trashed ones included (restoring must never clash), and
     * whatever its case.
     */
    private function uniqueCodeRule(?int $companyId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($companyId): void {
            if (trim((string) $value) !== '' && GiftCard::codeExists((string) $value, $companyId)) {
                $fail('This gift card code is already used by another card.');
            }
        };
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery(GiftCard::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $cards = $this->paginateSorted($query, $this->withSafeSort($request));

        $cards->getCollection()->transform(fn (GiftCard $card) => $this->withNames($card));

        $trash_count = GiftCard::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $cards, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/giftcard/add');

        $request->validate($this->giftCardFormRules($request));

        DB::beginTransaction();
        try {
            $card = GiftCard::issue($request);
            DB::commit();
        } catch (ValidationException|ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved', 'id' => $card->id, 'code' => $card->code]);
    }

    public function show($id): JsonResponse
    {
        $card = GiftCard::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'contact:id,business_name,first_name,last_name'])
            ->find($id);

        if ($card === null) {
            abort(404);
        }

        $card = $this->withNames($card);
        $card->setRelation('entries', $card->entries()->with('user:id,first_name,last_name')->orderByDesc('id')->limit(200)->get());

        return response()->json($card);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/giftcard/:id/edit');

        $request->validate($this->giftCardFormRules($request, (int) $id));

        DB::beginTransaction();
        try {
            GiftCard::updateGiftCard($request, $id);
            DB::commit();
        } catch (ValidationException|ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/giftcard/delete')) {
            GiftCard::deleteGiftCard($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/giftcard/delete', 'Successfully Deleted', function () use ($request) {
            GiftCard::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/giftcard/delete', 'Successfully Deleted', function () use ($request) {
            GiftCard::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/giftcard/restore', 'Successfully Restored', function () use ($request) {
            GiftCard::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/giftcard/:id/edit');

        $cards = GiftCard::query()
            ->visibleToCurrentUser()
            ->whereIn('id', (array) $request->ids)
            ->get();

        if ($cards->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($cards as $card) {
                $card->is_active = isset($request->status) ? (bool) $request->status : ! $card->is_active;
                $card->save();
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function trash(Request $request): JsonResponse
    {
        $query = $this->listQuery(GiftCard::onlyTrashed(), $request);

        $cards = $this->paginateSorted($query, $this->withSafeSort($request));

        $cards->getCollection()->transform(fn (GiftCard $card) => $this->withNames($card));

        return response()->json(['data' => $cards]);
    }

    /**
     * The cards of the user's company that are switched on, not expired and still have a balance, for a
     * dropdown (`text` is the code).
     */
    public function fetch(Request $request): JsonResponse
    {
        $cards = GiftCard::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString()))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('gift_cards.*')
            ->selectRaw('code as text')
            ->orderBy('code')
            ->get();

        return response()->json($cards);
    }

    /**
     * Balance check by code: the card and whether it can be spent today. A 422 with the reason
     * `not_found` when the company has no such live card. Open to every signed-in user of the company
     * since it only reads that company's own cards.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:50',
            'company_id' => 'nullable|integer',
        ]);

        $companyId = $this->requestCompanyId($request);
        $card = $companyId === null ? null : GiftCard::findByCode($data['code'], $companyId);

        if ($card === null) {
            return $this->rejection('not_found');
        }

        $reason = $card->rejectionReason();

        return response()->json([
            'id' => $card->id,
            'code' => $card->code,
            'balance' => (float) $card->balance,
            'initial_value' => (float) $card->initial_value,
            'expires_at' => $card->expires_at?->toDateString(),
            'is_active' => $card->is_active,
            'usable' => $reason === null,
            'reason' => $reason,
        ]);
    }

    /**
     * Refunds owed for gift card payments whose card was deleted for good (see SaleGiftCards): the open
     * ones by default, `status=all` for the settled ones too. A superadmin sees every company's. Needs the
     * Orphaned Refunds page permission.
     */
    public function orphanRefunds(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/giftcard/orphan-refunds');

        $rows = GiftCardOrphanRefund::query()
            ->with(['transaction:id,invoice_no', 'company:id,name'])
            ->when(! Auth::user()?->hasRole('superadmin'), fn ($q) => $q->where('company_id', Auth::user()?->company_id ?: 0))
            ->when($request->input('status') !== 'all', fn ($q) => $q->whereNull('resolved_at'))
            ->orderByDesc('id')
            ->get();

        // the card is gone by definition, but a new card may have been issued under the same code since
        $reissued = GiftCard::query()
            ->whereIn('code', $rows->pluck('gift_card_code')->unique())
            ->get(['id', 'company_id', 'code'])
            ->keyBy(fn (GiftCard $card): string => $card->company_id.'|'.$card->code);

        $data = $rows->map(fn (GiftCardOrphanRefund $row): array => [
            'id' => $row->id,
            'company_name' => $row->company?->name,
            'gift_card_code' => $row->gift_card_code,
            'gift_card_id' => $reissued->get($row->company_id.'|'.$row->gift_card_code)?->id,
            'transaction_id' => $row->transaction_id,
            'invoice_no' => $row->transaction?->invoice_no,
            'amount' => $row->amount,
            'reason' => $row->reason,
            'resolved_at' => $row->resolved_at?->toDateTimeString(),
            'resolved_note' => $row->resolved_note,
            'created_at' => $row->created_at?->toDateTimeString(),
        ]);

        return response()->json(['data' => $data, 'open_total' => round((float) $data->whereNull('resolved_at')->sum('amount'), 2)]);
    }

    /**
     * Marks an orphaned refund as settled by hand. Once settled it stays settled: a second attempt is refused
     * and never overwrites the first note or date.
     */
    public function resolveOrphanRefund(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/giftcard/topup');

        $data = $request->validate(['note' => 'required|string|min:3|max:500']);

        $row = GiftCardOrphanRefund::query()
            ->when(! Auth::user()?->hasRole('superadmin'), fn ($q) => $q->where('company_id', Auth::user()?->company_id ?: 0))
            ->find($id);

        if ($row === null) {
            abort(404);
        }

        $settled = GiftCardOrphanRefund::query()
            ->whereKey($row->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_note' => trim($data['note']), 'updated_at' => now()]);

        if ($settled === 0) {
            return response()->json(['message' => 'This refund has already been resolved.'], 422);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    /**
     * Spends part of a card's balance, optionally against the sale it paid.
     */
    public function redeem(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/giftcard/redeem');

        return $this->moveMoney($request, $id, redeeming: true);
    }

    /**
     * Adds money to a card.
     */
    public function topup(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/giftcard/topup');

        return $this->moveMoney($request, $id, redeeming: false);
    }

    private function moveMoney(Request $request, mixed $id, bool $redeeming): JsonResponse
    {
        $card = GiftCard::query()->visibleToCurrentUser()->find($id);

        if ($card === null) {
            abort(404);
        }

        $data = $request->validate([
            'amount' => 'required|numeric|decimal:0,2|gt:0|max:9999999.99',
            'note' => 'nullable|string|max:500',
            'transaction_id' => ['nullable', 'integer', Rule::exists('transactions', 'id')->where('company_id', $card->company_id)],
        ]);

        try {
            $entry = $redeeming
                ? $card->redeem((float) $data['amount'], $data['note'] ?? null, $data['transaction_id'] ?? null, Auth::id())
                : $card->topUp((float) $data['amount'], $data['note'] ?? null, Auth::id());
        } catch (RuntimeException $e) {
            return $this->rejection($e->getMessage());
        }

        return response()->json([
            'message' => 'Successfully Saved',
            'entry_id' => $entry->id,
            'balance' => (float) $card->balance,
        ]);
    }

    private function rejection(string $reason): JsonResponse
    {
        return response()->json(['message' => self::REJECTIONS[$reason] ?? 'This gift card cannot be used.', 'reason' => $reason], 422);
    }

    private function requestCompanyId(Request $request): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return GiftCard::resolveScopedId($request->company_id);
        }

        return $user?->company_id ? (int) $user->company_id : null;
    }

    private function withNames(GiftCard $card): GiftCard
    {
        $card->company_name = $card->company?->name;
        $card->contact_name = $card->holderName();

        return $card;
    }

    /**
     * @param  Builder<GiftCard>  $query
     * @return Builder<GiftCard>
     */
    private function listQuery($query, Request $request)
    {
        $status = $request->status ?? 'all';
        $search = trim((string) ($request->search ?? ''));

        return $query
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'contact:id,business_name,first_name,last_name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('code', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), GiftCard::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
