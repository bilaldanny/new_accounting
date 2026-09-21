<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Contact;
use App\Models\LoyaltyPointEntry;
use App\Models\LoyaltySetting;
use App\Models\Transaction;
use App\Services\LoyaltyPoints;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The loyalty programme: customers with a points balance, their ledger, the company's settings, and the
 * three ways points move (earn for a sale, redeem, manual adjust). The rules live in `LoyaltyPoints`.
 */
class LoyaltyController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * Columns the customer list may be sorted by.
     *
     * @var list<string>
     */
    private const SORTABLE = ['business_name', 'first_name', 'mobile', 'points_balance', 'created_at'];

    /**
     * What the movement endpoints tell the caller for each refusal reason.
     *
     * @var array<string, string>
     */
    private const REJECTIONS = [
        'disabled' => 'The loyalty programme is not switched on for this company.',
        'not_a_sale' => 'Points can only be earned on a finished sale.',
        'no_customer' => 'This sale has no customer to award points to.',
        'already_awarded' => 'Points were already awarded for this sale.',
        'no_points' => 'This sale is too small to earn a point.',
        'below_minimum' => 'The points are below the minimum that can be redeemed.',
        'insufficient_points' => 'The customer does not have that many points.',
        'negative_balance' => 'The adjustment would take the balance below zero.',
    ];

    public function __construct(private readonly LoyaltyPoints $points) {}

    /**
     * Customers that have any points activity, with their balance.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->search ?? ''));

        $query = Contact::query()
            ->customers()
            ->visibleToCurrentUser()
            ->whereIn('contacts.id', LoyaltyPointEntry::query()->select('contact_id'))
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('contacts.company_id', $request->integer('company_id')))
            ->when($search !== '', function (Builder $q) use ($search) {
                $q->where(function (Builder $sub) use ($search) {
                    $sub->where('business_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->select('contacts.*')
            ->addSelect(['points_balance' => LoyaltyPointEntry::query()
                ->selectRaw('COALESCE(SUM(points), 0)')
                ->whereColumn('loyalty_point_entries.contact_id', 'contacts.id')])
            ->with('company:id,name');

        $customers = $this->paginateSorted($query, $this->withSafeSort($request));

        $customers->getCollection()->transform(function (Contact $contact) {
            $contact->name = $this->customerName($contact);
            $contact->company_name = $contact->company?->name;
            $contact->points_balance = (int) $contact->points_balance;

            return $contact;
        });

        return response()->json(['data' => $customers]);
    }

    /**
     * One customer: balance, lifetime totals, the company's settings and the latest 200 ledger lines.
     */
    public function show($id): JsonResponse
    {
        $contact = Contact::query()->customers()->visibleToCurrentUser()->with('company:id,name')->find($id);

        if ($contact === null) {
            abort(404);
        }

        $entries = LoyaltyPointEntry::query()->where('contact_id', $contact->id);
        $settings = LoyaltySetting::forCompany((int) $contact->company_id);
        $balance = $this->points->balance($contact->id);

        return response()->json([
            'id' => $contact->id,
            'name' => $this->customerName($contact),
            'mobile' => $contact->mobile,
            'company_name' => $contact->company?->name,
            'balance' => $balance,
            'balance_value' => $settings->valueOf($balance),
            'earned_total' => (int) (clone $entries)->where('points', '>', 0)->sum('points'),
            'redeemed_total' => (int) abs((clone $entries)->where('type', LoyaltyPointEntry::TYPE_REDEEM)->sum('points')),
            'settings' => $this->settingsPayload($settings),
            'entries' => LoyaltyPointEntry::query()
                ->where('contact_id', $contact->id)
                ->with('user:id,first_name,last_name')
                ->orderByDesc('id')
                ->limit(200)
                ->get(),
        ]);
    }

    /**
     * The settings of the user's company (a switched-off default when never saved); the superadmin names the company.
     */
    public function settings(Request $request): JsonResponse
    {
        $companyId = $this->requestCompanyId($request);

        if ($companyId === null) {
            return response()->json(['message' => 'Choose a company.'], 422);
        }

        return response()->json($this->settingsPayload(LoyaltySetting::forCompany($companyId)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/loyalty/settings');

        $data = $request->validate([
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'is_enabled' => 'required|boolean',
            'amount_per_point' => 'required|numeric|decimal:0,2|gt:0|max:9999999.99',
            'point_value' => 'required|numeric|decimal:0,2|gt:0|max:9999999.99',
            'min_redeem_points' => 'nullable|integer|min:0|max:1000000',
        ]);

        $companyId = $this->requestCompanyId($request);

        if ($companyId === null) {
            return response()->json(['message' => 'Choose a company.'], 422);
        }

        $settings = LoyaltySetting::query()->updateOrCreate(['company_id' => $companyId], [
            'is_enabled' => (bool) $data['is_enabled'],
            'amount_per_point' => (float) $data['amount_per_point'],
            'point_value' => (float) $data['point_value'],
            'min_redeem_points' => (int) ($data['min_redeem_points'] ?? 0),
        ]);

        return response()->json(['message' => 'Successfully Saved'] + $this->settingsPayload($settings));
    }

    /**
     * Awards the points a finished sale earns, once per sale.
     */
    public function earn(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/loyalty/earn');

        $data = $request->validate(['transaction_id' => 'required|integer']);

        $companyId = $this->visibleCompanyId();

        $sale = Transaction::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->find($data['transaction_id']);

        if ($sale === null) {
            abort(404);
        }

        return $this->movement(fn () => $this->points->earnForSale($sale, Auth::id()));
    }

    public function redeem(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/loyalty/redeem');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'points' => 'required|integer|min:1|max:1000000',
            'note' => 'nullable|string|max:500',
        ]);

        $contact = $this->findCustomer($data['contact_id']);

        $data += $request->validate([
            'transaction_id' => ['nullable', 'integer', Rule::exists('transactions', 'id')->where('company_id', $contact->company_id)],
        ]);

        return $this->movement(fn () => $this->points->redeem($contact, (int) $data['points'], $data['note'] ?? null, $data['transaction_id'] ?? null, Auth::id()));
    }

    public function adjust(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/loyalty/adjust');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => 'required|string|min:3|max:500',
        ]);

        $contact = $this->findCustomer($data['contact_id']);

        return $this->movement(fn () => $this->points->adjust($contact, (int) $data['points'], trim($data['note']), Auth::id()));
    }

    /**
     * @param  callable(): LoyaltyPointEntry  $move
     */
    private function movement(callable $move): JsonResponse
    {
        try {
            $entry = DB::transaction($move);
        } catch (RuntimeException $e) {
            return response()->json(['message' => self::REJECTIONS[$e->getMessage()] ?? 'The points could not be moved.', 'reason' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Successfully Saved',
            'entry_id' => $entry->id,
            'points' => $entry->points,
            'balance' => $entry->balance_after,
            'amount' => $entry->amount === null ? null : (float) $entry->amount,
        ]);
    }

    private function findCustomer(mixed $id): Contact
    {
        $contact = Contact::query()->customers()->visibleToCurrentUser()->find($id);

        if ($contact === null) {
            abort(404);
        }

        return $contact;
    }

    /**
     * The company whose sales the user may award points for: their own (0, matching nothing, when they
     * have none); null for the superadmin, who is not restricted to one.
     */
    private function visibleCompanyId(): ?int
    {
        $user = Auth::user();

        return $user?->hasRole('superadmin') ? null : ($user?->company_id ? (int) $user->company_id : 0);
    }

    private function requestCompanyId(Request $request): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $request->filled('company_id') ? $request->integer('company_id') : null;
        }

        return $user?->company_id ? (int) $user->company_id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(LoyaltySetting $settings): array
    {
        return [
            'company_id' => $settings->company_id,
            'is_enabled' => $settings->is_enabled,
            'amount_per_point' => $settings->amount_per_point,
            'point_value' => $settings->point_value,
            'min_redeem_points' => $settings->min_redeem_points,
        ];
    }

    private function customerName(Contact $contact): string
    {
        $name = trim((string) $contact->business_name);

        return $name !== '' ? $name : trim($contact->first_name.' '.$contact->last_name);
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), self::SORTABLE, true)) {
            $request->merge(['sort_by' => 'points_balance']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
