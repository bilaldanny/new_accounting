<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Something a customer or supplier sent in through the portal and staff have yet to look at: a quotation or order
 * request (lines), or a supplier's bill document. Nothing here touches the books; an approved customer request
 * becomes a quotation or draft sale (`transaction_id`), an approved bill is only marked as accepted for entry.
 */
class PortalRequest extends Model
{
    use Auditable;

    public const KINDS = ['quotation', 'order', 'bill'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public const DISK = 'local';

    protected $fillable = [
        'company_id', 'branch_id', 'contact_id', 'kind', 'status', 'reference', 'note', 'lines', 'purchase_id', 'bill_no', 'bill_date',
        'amount', 'file_name', 'file_path', 'file_mime', 'file_size', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'bill_date' => 'date',
            'amount' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Gives a freshly created request its reference (PR-000012), which is unique across the table.
     */
    public function assignReference(): void
    {
        $this->forceFill(['reference' => 'PR-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
    }

    public function discardFile(): void
    {
        if (filled($this->file_path)) {
            Storage::disk(self::DISK)->delete($this->file_path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind,
            'status' => $this->status,
            'note' => $this->note,
            'lines' => $this->lines ?? [],
            'purchase_id' => $this->purchase_id,
            'bill_no' => $this->bill_no,
            'bill_date' => $this->bill_date?->toDateString(),
            'amount' => $this->amount,
            'file_name' => $this->file_name,
            'has_file' => filled($this->file_path),
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'transaction_id' => $this->transaction_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
