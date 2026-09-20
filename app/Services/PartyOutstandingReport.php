<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\FinancialYear;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What every customer still owes, or every supplier is still owed, on a given day.
 *
 * The figure is the contact's ledger closing balance (ContactLedger), so this report and the party
 * ledger screen never disagree: journal postings when the contact has any, otherwise the documents
 * and payments, plus the stored opening balance. It runs from the start of the active financial year
 * (or all time when there is none) up to the "as of" day, inclusive; drafts and quotations are left
 * out like everywhere else. A contact is looked at from the side of the report: a contact who is both
 * customer and supplier shows their receivable in the customer report and their payable in the
 * supplier report, never a mix of the two. A contact without a chart-of-accounts account is measured
 * from its documents instead of failing.
 *
 * A positive balance is money still to collect (customer) or still to pay (supplier); a negative one
 * is an advance or a credit the other way. Contacts that are settled are left out unless asked for.
 */
class PartyOutstandingReport
{
    public const KINDS = ['customer', 'supplier'];

    public const SORTABLE = [
        'contact_name' => 'contact_name',
        'code' => 'code',
        'group_name' => 'group_name',
        'balance' => 'balance',
    ];

    /**
     * @var array<int|string, string>
     */
    private array $periodStarts = [];

    public function __construct(private readonly ContactLedger $ledger) {}

    /**
     * @param  array{contact_id?: mixed, customer_group_id?: mixed, end_date?: ?string, include_zero?: mixed, search?: ?string}  $filters
     * @return Collection<int, array{id: int, contact_name: string, code: string, account_code: string, group_name: string, balance: float, position: string}>
     */
    public function rows(string $kind, ?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown outstanding report [{$kind}].");
        }

        $asOf = $this->asOf($filters['end_date'] ?? null);
        $includeZero = filter_var($filters['include_zero'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $contacts = $this->contacts($kind, $companyId, $branchId, $filters)->get();
        $groups = DB::table('customer_groups')
            ->whereIn('id', $contacts->pluck('customer_group_id')->filter()->unique())
            ->pluck('name', 'id');

        return $contacts
            ->map(function (Contact $contact) use ($kind, $branchId, $asOf, $groups): array {
                $balance = $this->balance($contact, $kind, $asOf, $branchId);

                return [
                    'id' => $contact->id,
                    'contact_name' => self::contactName($contact),
                    'code' => (string) $contact->code,
                    'account_code' => (string) ($kind === 'customer' ? $contact->customer_gl_id : $contact->supplier_gl_id),
                    'group_name' => (string) ($groups[$contact->customer_group_id] ?? ''),
                    'balance' => $balance,
                    'position' => match (true) {
                        $balance > 0 => 'due',
                        $balance < 0 => 'advance',
                        default => 'settled',
                    },
                ];
            })
            ->when(! $includeZero, fn (Collection $rows) => $rows->where('position', '!=', 'settled'))
            ->values();
    }

    /**
     * @param  Collection<int, array{balance: float}>  $rows
     * @return array{count: int, total_due: float, total_advance: float, net: float, as_of: string}
     */
    public function summary(Collection $rows, ?string $endDate = null): array
    {
        $due = round((float) $rows->where('balance', '>', 0)->sum('balance'), 2);
        $advance = round(abs((float) $rows->where('balance', '<', 0)->sum('balance')), 2);

        return [
            'count' => $rows->count(),
            'total_due' => $due,
            'total_advance' => $advance,
            'net' => round($due - $advance, 2),
            'as_of' => $this->asOf($endDate)->toDateString(),
        ];
    }

    public static function contactName(Contact $contact): string
    {
        $business = trim((string) $contact->business_name);

        if ($business !== '') {
            return $business;
        }

        $person = trim(trim((string) $contact->first_name).' '.trim((string) $contact->last_name));

        return $person !== '' ? $person : '-';
    }

    private function balance(Contact $contact, string $kind, CarbonInterface $asOf, ?int $branchId): float
    {
        $view = $contact->replicate();
        $view->id = $contact->id;
        $view->user_type = $kind;

        $start = Carbon::parse($this->periodStart($contact->company_id));
        $from = $asOf->lt($start) ? $asOf->copy() : $start;

        return $this->ledger->closingBalance($view, $from, $asOf, $branchId === null ? null : (string) $branchId, postedOnly: true);
    }

    private function periodStart(int|string|null $companyId): string
    {
        return $this->periodStarts[$companyId] ??= (
            FinancialYear::query()
                ->where('company_id', $companyId)
                ->where('status', true)
                ->orderByDesc('id')
                ->first()?->start_date?->toDateString() ?? '2000-01-01'
        );
    }

    private function asOf(?string $endDate): CarbonInterface
    {
        return $endDate !== null && trim($endDate) !== '' ? Carbon::parse($endDate)->startOfDay() : Carbon::today();
    }

    /**
     * @param  array{contact_id?: mixed, customer_group_id?: mixed, search?: ?string}  $filters
     * @return Builder<Contact>
     */
    private function contacts(string $kind, ?int $companyId, ?int $branchId, array $filters): Builder
    {
        $contactId = $filters['contact_id'] ?? null;
        $groupId = $filters['customer_group_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        return Contact::query()
            ->whereIn('user_type', [$kind, 'both'])
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when(! empty($contactId), fn (Builder $query) => $query->where('id', $contactId))
            ->when($kind === 'customer' && ! empty($groupId), fn (Builder $query) => $query->where('customer_group_id', $groupId))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('business_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->orderBy('id');
    }
}
