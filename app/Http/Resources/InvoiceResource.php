<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'customer_id' => $this->contact_id,
            'customer_name' => $this->contact?->business_name ?: trim((string) $this->contact?->first_name.' '.(string) $this->contact?->last_name),
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'final_amount' => (float) $this->final_amount,
            'paid_amount' => (float) $this->paid_amount,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
