<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseApprovalStep extends Model
{
    protected $fillable = ['transaction_id', 'level', 'approved_by', 'role_id', 'note'];
}
