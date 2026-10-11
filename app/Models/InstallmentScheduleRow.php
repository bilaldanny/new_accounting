<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallmentScheduleRow extends Model
{
    protected $table = 'installment_schedule';

    protected $fillable = ['installment_plan_id', 'number', 'due_on', 'amount', 'paid_amount', 'paid_on'];

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d', 'amount' => 'float', 'paid_amount' => 'float'];
    }

    public function status(): string
    {
        if ($this->paid_amount + 0.005 >= $this->amount) {
            return 'paid';
        }

        return $this->due_on !== null && $this->due_on->toDateString() < now()->toDateString() ? 'overdue' : 'pending';
    }
}
