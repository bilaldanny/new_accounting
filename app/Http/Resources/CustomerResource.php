<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'business_name' => $this->business_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'credit_limit' => $this->credit_limit !== null ? (float) $this->credit_limit : null,
            'is_active' => (bool) $this->active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
