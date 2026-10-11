<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesRouteStop extends Model
{
    protected $fillable = ['sales_route_id', 'contact_id', 'sequence', 'note'];
}
