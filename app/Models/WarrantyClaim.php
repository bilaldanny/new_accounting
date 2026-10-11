<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer after-sales claim against a product warranty. Whether the claim falls inside the warranty is worked
 * out when it is filed (from the sale date and the warranty duration) and kept on the claim.
 */
class WarrantyClaim extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const STATUSES = ['open', 'in_review', 'approved', 'rejected', 'resolved'];

    public const RESOLUTIONS = ['repair', 'replace', 'refund', 'none'];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'claim_no', 'product_id', 'variation_id', 'contact_id', 'transaction_id', 'serial_no', 'issue',
        'claimed_on', 'warranty_expires_on', 'in_warranty', 'status', 'resolution', 'resolution_note', 'resolved_on', 'created_by',
    ];

    protected function casts(): array
    {
        return ['claimed_on' => 'date:Y-m-d', 'warranty_expires_on' => 'date:Y-m-d', 'resolved_on' => 'date:Y-m-d', 'in_warranty' => 'boolean'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
