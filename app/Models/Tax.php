<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class Tax extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'percentage',
        'sub_tax',
        'type',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'integer',
            'type' => 'integer',
            'status' => 'boolean',
            'sub_tax' => 'array',
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
        if ((int) $request->input('type') === 1) {
            $rate = 0;

            foreach ((array) $request->input('sub_tax', []) as $taxId) {
                $subTax = self::query()->find((int) $taxId);
                $rate += (int) ($subTax?->percentage ?? 0);
            }

            return self::query()->create([
                'company_id' => $request->integer('company_id') ?: null,
                'name' => $request->string('name')->toString(),
                'percentage' => $rate,
                'sub_tax' => array_values((array) $request->input('sub_tax', [])),
                'type' => 1,
                'status' => $request->boolean('status', true),
            ]);
        }

        return self::query()->create([
            'company_id' => $request->integer('company_id') ?: null,
            'name' => $request->string('name')->toString(),
            'percentage' => $request->integer('percentage'),
            'type' => 0,
            'status' => $request->boolean('status', true),
        ]);
    }

    public function updateFromRequest(Request $request): self
    {
        if ((int) $request->input('type') === 1) {
            $rate = 0;

            foreach ((array) $request->input('sub_tax', []) as $taxId) {
                $subTax = self::query()->find((int) $taxId);
                $rate += (int) ($subTax?->percentage ?? 0);
            }

            $this->update([
                'company_id' => $request->integer('company_id') ?: null,
                'name' => $request->string('name')->toString(),
                'percentage' => $rate,
                'sub_tax' => array_values((array) $request->input('sub_tax', [])),
                'type' => 1,
                'status' => $request->boolean('status', (bool) $this->status),
            ]);

            return $this;
        }

        $this->update([
            'company_id' => $request->integer('company_id') ?: null,
            'name' => $request->string('name')->toString(),
            'percentage' => $request->integer('percentage'),
            'type' => 0,
            'sub_tax' => null,
            'status' => $request->boolean('status', (bool) $this->status),
        ]);

        return $this;
    }
}
