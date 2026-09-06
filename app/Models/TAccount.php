<?php

namespace App\Models;

use App\Services\LedgerJournal;
use App\Support\Base64Upload;
use Database\Factories\TAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TAccount extends Model
{
    /** @use HasFactory<TAccountFactory> */
    use HasFactory;

    public const VOUCHER_TYPES = ['JV', 'JE'];

    public const PAYMENT_VOUCHER_TYPES = ['BP', 'CP', 'OP'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'coa_id',
        'transaction_id',
        'received_id',
        'created_by',
        'approved_by',
        'cancelled_by',
        'printed_by',
        'issuer_id',
        'account_code',
        'voucher_no',
        'ref_no',
        'cheque_no',
        'cheque_post_date',
        'voucher_date',
        'total_amount',
        'total_tax',
        'net_total',
        'is_print',
        'comments',
        'status',
        'type',
        'printed_at',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cheque_post_date' => 'date',
            'voucher_date' => 'date',
            'total_amount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'net_total' => 'decimal:2',
            'is_print' => 'boolean',
            'printed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return HasMany<TAccountDetail, $this>
     */
    public function details(): HasMany
    {
        return $this->hasMany(TAccountDetail::class, 't_account_id');
    }

    /**
     * @return HasMany<TAccountAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TAccountAttachment::class, 't_account_id');
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        $query->where('company_id', $user->company_id);

        if ($user?->branch_id && ! $user?->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public function scopeManualJournals(Builder $query): Builder
    {
        return $query
            ->whereNull('transaction_id')
            ->where(function (Builder $voucherQuery) {
                $voucherQuery
                    ->where('voucher_no', 'like', 'JV-%')
                    ->orWhere('voucher_no', 'like', 'JE-%');
            });
    }

    public function scopeManualPayments(Builder $query): Builder
    {
        return $query
            ->whereNull('transaction_id')
            ->where(function (Builder $voucherQuery) {
                $voucherQuery
                    ->where('voucher_no', 'like', 'BP-%')
                    ->orWhere('voucher_no', 'like', 'CP-%')
                    ->orWhere('voucher_no', 'like', 'OP-%');
            });
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function findVisibleManualJournal(int $id): ?self
    {
        return self::findVisibleManualVoucher($id, 'journal');
    }

    public static function findVisibleManualPayment(int $id): ?self
    {
        return self::findVisibleManualVoucher($id, 'payment');
    }

    public static function findVisibleManualVoucher(int $id, string $family = 'journal'): ?self
    {
        $query = self::query()->visibleToCurrentUser();

        if ($family === 'payment') {
            $query->manualPayments();
        } else {
            $query->manualJournals();
        }

        return $query->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForIndex(): array
    {
        $voucherDate = $this->voucher_date?->format('Y-m-d');

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'company_name' => $this->company?->name,
            'branch_name' => $this->branch?->name,
            'voucher_no' => $this->voucher_no,
            'voucher_type' => Str::before((string) $this->voucher_no, '-'),
            'kind_label' => self::voucherKindLabel((string) $this->voucher_no),
            'voucher_date' => $voucherDate,
            'voucher_date_label' => $voucherDate,
            'total_amount' => $this->total_amount,
            'formatted_amount' => number_format((float) $this->total_amount, 2),
            'status' => $this->status,
            'status_label' => Str::headline((string) $this->status),
            'comments' => $this->comments,
        ];
    }

    public static function createJournalEntry(Request $request): self
    {
        $journal = new self;
        $journal->fillFromRequest($request, isNew: true);
        $journal->save();
        $journal->syncDetails($request->input('taccountdetails', []));
        $journal->syncAttachments($request->input('attachments', []));
        $journal->assertBalanced();

        return $journal;
    }

    public static function updateJournalEntry(Request $request, int $id, string $family = 'journal'): self
    {
        $journal = self::findVisibleManualVoucher($id, $family);

        if ($journal === null) {
            abort(404);
        }

        $journal->fillFromRequest($request, isNew: false);
        $journal->save();
        $journal->syncDetails($request->input('taccountdetails', []));
        $journal->syncAttachments($request->input('attachments', []));
        $journal->assertBalanced();

        return $journal;
    }

    public static function deleteJournalEntry(int $id, string $family = 'journal'): void
    {
        $journal = self::findVisibleManualVoucher($id, $family);

        if ($journal === null) {
            abort(404);
        }

        $journal->delete();
    }

    public static function duplicateJournalEntry(int $id, string $family = 'journal'): self
    {
        $journal = self::findVisibleManualVoucher($id, $family);

        if ($journal === null) {
            abort(404);
        }

        $duplicate = $journal->replicate([
            'approved_by',
            'approved_at',
            'cancelled_by',
            'printed_by',
            'printed_at',
            'is_print',
        ]);
        $duplicate->voucher_no = app(LedgerJournal::class)->nextVoucherNo(
            (int) $journal->company_id,
            (int) $journal->branch_id,
            self::voucherTypeFromNumber((string) $journal->voucher_no),
        );
        $duplicate->status = self::resolveStatus((int) $journal->company_id);
        $duplicate->created_by = Auth::id();
        $duplicate->transaction_id = null;
        $duplicate->save();

        foreach ($journal->details as $line) {
            $copy = $line->replicate();
            $copy->t_account_id = $duplicate->id;
            $copy->save();
        }

        return $duplicate;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForForm(): array
    {
        $this->loadMissing(['details.account:id,code,name,acc_nature', 'attachments', 'company:id,name', 'branch:id,name']);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'company_name' => $this->company?->name,
            'branch_name' => $this->branch?->name,
            'voucher_type' => self::voucherTypeFromNumber((string) $this->voucher_no),
            'voucher_no' => $this->voucher_no,
            'voucher_date' => $this->voucher_date?->format('Y-m-d'),
            'comments' => $this->comments,
            'cheque_no' => $this->cheque_no,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'total_tax' => $this->total_tax,
            'net_total' => $this->net_total,
            'taccountdetails' => $this->details->map(function (TAccountDetail $line) {
                return [
                    'id' => $line->id,
                    'account_id' => $line->coa_id,
                    'code' => $line->account_code,
                    'account_name' => $line->account?->name,
                    'account_nature' => $line->acc_nature,
                    'description' => $line->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                ];
            })->values()->all(),
            'attachments' => $this->attachments->map(function (TAccountAttachment $attachment) {
                return [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'data_url' => $attachment->data_url,
                    'ext' => $attachment->ext,
                ];
            })->values()->all(),
        ];
    }

    private function fillFromRequest(Request $request, bool $isNew): void
    {
        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);
        $voucherType = strtoupper((string) $request->input('voucher_type', 'JV'));

        if (! in_array($voucherType, self::allVoucherTypes(), true)) {
            $voucherType = 'JV';
        }

        $this->company_id = $companyId;
        $this->branch_id = $branchId;
        $this->voucher_date = $request->input('voucher_date');
        $this->comments = (string) $request->input('comments', $request->input('comment', ''));
        $this->total_tax = 0;
        $this->type = self::ledgerTypeForVoucher($voucherType);
        $this->ref_no = (string) $request->input('ref_no', '');
        $this->cheque_no = $voucherType === 'OP'
            ? 'ONLINE'
            : (string) $request->input('cheque_no', '');
        $this->account_code = $this->headerAccountCode($request);
        $this->coa_id = $this->headerAccountId($request);

        $totals = $this->lineTotals($request->input('taccountdetails', []));
        $this->total_amount = $totals['debit'];
        $this->net_total = $totals['debit'];

        if ($isNew) {
            $this->created_by = Auth::id();
            $this->status = self::resolveStatus((int) $companyId);
            $this->voucher_no = filled($request->input('voucher_no'))
                ? (string) $request->input('voucher_no')
                : app(LedgerJournal::class)->nextVoucherNo((int) $companyId, (int) $branchId, $voucherType);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncDetails(array $lines): void
    {
        $this->details()->delete();

        foreach ($lines as $line) {
            $accountId = (int) ($line['account_id'] ?? 0);
            $account = ChartOfAccount::query()->find($accountId);

            if ($account === null) {
                continue;
            }

            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit < 0.0 || $credit < 0.0) {
                throw ValidationException::withMessages([
                    'taccountdetails' => ['Journal lines cannot have a negative debit or credit amount.'],
                ]);
            }

            if ($debit === 0.0 && $credit === 0.0) {
                throw ValidationException::withMessages([
                    'taccountdetails' => ['Each journal line must have a positive debit or credit amount.'],
                ]);
            }

            if ($debit > 0.0 && $credit > 0.0) {
                throw ValidationException::withMessages([
                    'taccountdetails' => ['A journal line cannot have both a debit and a credit amount.'],
                ]);
            }

            $this->details()->create([
                'branch_id' => $this->branch_id,
                'coa_id' => $account->id,
                'account_code' => (string) $account->code,
                'description' => (string) ($line['description'] ?? ''),
                'acc_nature' => $account->acc_nature ?: ($debit > 0 ? 'dr' : 'cr'),
                'debit' => $debit,
                'credit' => $credit,
                'amount' => 0,
                'highlight' => false,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     */
    private function syncAttachments(array $attachments): void
    {
        $keepIds = [];

        foreach ($attachments as $attachment) {
            if (($attachment['deleted'] ?? false) === true || ($attachment['deleted'] ?? 0) == 1) {
                if (isset($attachment['id'])) {
                    $this->attachments()->where('id', $attachment['id'])->delete();
                }

                continue;
            }

            if (isset($attachment['id'])) {
                $keepIds[] = (int) $attachment['id'];

                continue;
            }

            $stored = $this->storeAttachmentFile($attachment);

            if ($stored === null) {
                continue;
            }

            $created = $this->attachments()->create($stored);
            $keepIds[] = $created->id;
        }

        $this->attachments()
            ->when($keepIds !== [], fn (Builder $query) => $query->whereNotIn('id', $keepIds))
            ->when($keepIds === [], fn (Builder $query) => $query)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array{file_name: string, file_url: string, ext: string}|null
     */
    private function storeAttachmentFile(array $file): ?array
    {
        $dataUrl = $file['data_url'] ?? null;

        if (! is_string($dataUrl)) {
            return null;
        }

        $decoded = Base64Upload::decode($dataUrl);

        if ($decoded === null) {
            return null;
        }

        $originalName = (string) ($file['file_name'] ?? 'attachment');
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'attachment';
        $path = 'voucher_images/'.$safeName.'-'.Str::ulid().'.'.$decoded['extension'];

        Storage::disk('public')->put($path, $decoded['binary']);

        return [
            'file_name' => $originalName,
            'file_url' => $path,
            'ext' => $decoded['extension'],
        ];
    }

    private function assertBalanced(): void
    {
        $totals = $this->details()
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        $debits = round((float) ($totals->debit_total ?? 0), 2);
        $credits = round((float) ($totals->credit_total ?? 0), 2);

        if (! self::amountsEqual($debits, $credits) || $debits <= 0.0) {
            throw ValidationException::withMessages([
                'taccountdetails' => ['Journal is not balanced. Total debit must equal total credit.'],
            ]);
        }
    }

    /**
     * Decimal-safe equality for 2dp monetary amounts, comparing whole cents
     * instead of raw floats (which can differ by rounding noise even when
     * two independently-summed totals are mathematically equal).
     */
    private static function amountsEqual(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{debit: float, credit: float}
     */
    private function lineTotals(array $lines): array
    {
        $debit = 0.0;
        $credit = 0.0;

        foreach ($lines as $line) {
            $debit += (float) ($line['debit'] ?? 0);
            $credit += (float) ($line['credit'] ?? 0);
        }

        return [
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
        ];
    }

    private function headerAccountId(Request $request): ?int
    {
        $first = collect($request->input('taccountdetails', []))->first();

        return isset($first['account_id']) ? (int) $first['account_id'] : null;
    }

    private function headerAccountCode(Request $request): string
    {
        $first = collect($request->input('taccountdetails', []))->first();

        return (string) ($first['code'] ?? '000-00000');
    }

    public static function resolveStatus(int $companyId): string
    {
        if (Auth::user()?->hasRole('superadmin')) {
            return 'pending';
        }

        $requiresApproval = (bool) CompanySetting::query()
            ->where('company_id', $companyId)
            ->value('journal_entry');

        return $requiresApproval ? 'pending' : 'approved';
    }

    /**
     * @return list<string>
     */
    public static function allVoucherTypes(): array
    {
        return array_merge(self::VOUCHER_TYPES, self::PAYMENT_VOUCHER_TYPES);
    }

    public static function voucherTypeFromNumber(string $voucherNo): string
    {
        $prefix = strtoupper(Str::before($voucherNo, '-'));

        return in_array($prefix, self::allVoucherTypes(), true) ? $prefix : 'JV';
    }

    public static function voucherKindLabel(string $voucherNo): string
    {
        return match (self::voucherTypeFromNumber($voucherNo)) {
            'BP' => 'Bank Payment',
            'CP' => 'Cash Payment',
            'OP' => 'Online Payment',
            'JV' => 'Journal Voucher',
            'JE' => 'Journal Entry',
            default => 'Voucher',
        };
    }

    private static function ledgerTypeForVoucher(string $voucherType): string
    {
        return match ($voucherType) {
            'CP' => 'cash',
            'BP' => 'bank',
            default => 'online',
        };
    }
}
