<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class FinancialYear extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function storeFromRequest(Request $request): self
    {
        $startDate = Carbon::parse(str_replace('/', '-', (string) $request->input('start_date')))->format('Y-m-d');
        $endDate = Carbon::parse(str_replace('/', '-', (string) $request->input('end_date')))->format('Y-m-d');

        return self::query()->create([
            'company_id' => $request->integer('company_id') ?: null,
            'name' => $request->input('name') ?: "{$startDate} - {$endDate}",
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $request->boolean('status', true),
        ]);
    }

    public function updateFromRequest(Request $request): self
    {
        if ($request->input('updatetype') === 'status') {
            if ($request->boolean('status')) {
                self::query()
                    ->where('company_id', $this->company_id)
                    ->update(['status' => false]);
            }

            $this->update(['status' => $request->boolean('status')]);

            return $this;
        }

        $startDate = Carbon::parse(str_replace('/', '-', (string) $request->input('start_date')))->format('Y-m-d');
        $endDate = Carbon::parse(str_replace('/', '-', (string) $request->input('end_date')))->format('Y-m-d');

        $this->update([
            'company_id' => $request->integer('company_id') ?: null,
            'name' => $request->input('name') ?: "{$startDate} - {$endDate}",
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $request->boolean('status', (bool) $this->status),
        ]);

        return $this;
    }
}
