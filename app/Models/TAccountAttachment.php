<?php

namespace App\Models;

use Database\Factories\TAccountAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TAccountAttachment extends Model
{
    /** @use HasFactory<TAccountAttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        't_account_id',
        'file_name',
        'file_url',
        'ext',
    ];

    protected $appends = [
        'data_url',
    ];

    /**
     * @return BelongsTo<TAccount, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(TAccount::class, 't_account_id');
    }

    public function getDataUrlAttribute(): ?string
    {
        if (blank($this->file_url)) {
            return null;
        }

        return Storage::disk('public')->url($this->file_url);
    }
}
