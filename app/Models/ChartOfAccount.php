<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'parent_id',
        'code',
        'name',
        'acc_type',
        'acc_nature',
        'pl',
        'bs',
        'active',
        'branches',
    ];

    protected function casts(): array
    {
        return [
            'pl' => 'boolean',
            'bs' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
