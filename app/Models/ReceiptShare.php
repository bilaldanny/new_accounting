<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A receipt shared with a customer: a link (token), an email, or a WhatsApp / SMS message through the configured provider.
 */
class ReceiptShare extends Model
{
    use ScopedToCompany;

    public const CHANNELS = ['link', 'email', 'whatsapp', 'sms'];

    protected $fillable = ['company_id', 'transaction_id', 'token', 'channel', 'recipient', 'status', 'error', 'sent_at', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
