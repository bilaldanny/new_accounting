<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One level of invoice dunning: when it applies (days past due) and the text of its message.
 */
class DunningLevel extends Model
{
    protected $fillable = ['company_id', 'level', 'name', 'days_past_due', 'subject', 'body', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'level' => 'integer', 'days_past_due' => 'integer'];
    }
}
