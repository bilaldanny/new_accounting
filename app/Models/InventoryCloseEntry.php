<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One step of the inventory closing lock history: a close (locked_through set) or a reopen.
 */
class InventoryCloseEntry extends Model
{
    protected $table = 'inventory_closes';

    protected $fillable = ['company_id', 'branch_id', 'locked_through', 'action', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['locked_through' => 'date:Y-m-d'];
    }
}
